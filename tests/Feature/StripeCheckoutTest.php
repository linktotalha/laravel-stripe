<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\StripeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Tests\TestCase;

class StripeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Price $price;

    private Price $otherPrice;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.success_url' => 'https://app.test/subscription/success',
            'services.stripe.cancel_url' => 'https://app.test/subscription/cancel',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'stripe_customer_id' => 'cus_test123',
        ]);

        $product = Product::create([
            'stripe_product_id' => 'prod_test123',
            'name' => 'Pro',
            'active' => true,
        ]);

        $this->price = Price::create([
            'product_id' => $product->id,
            'stripe_price_id' => 'price_test123',
            'amount' => 1000,
            'currency' => 'usd',
            'interval' => 'month',
            'interval_count' => 1,
            'active' => true,
        ]);

        $this->otherPrice = Price::create([
            'product_id' => $product->id,
            'stripe_price_id' => 'price_test456',
            'amount' => 2500,
            'currency' => 'usd',
            'interval' => 'month',
            'interval_count' => 1,
            'active' => true,
        ]);
    }

    private function stripeSubscriptionData(string $stripePriceId = 'price_test123'): array
    {
        return [
            'id' => 'sub_test123',
            'object' => 'subscription',
            'customer' => 'cus_test123',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'metadata' => [],
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_test123',
                    'object' => 'subscription_item',
                    'price' => ['id' => $stripePriceId, 'object' => 'price'],
                    'current_period_start' => 1750000000,
                    'current_period_end' => 1752678400,
                ]],
            ],
        ];
    }

    private function existingSubscription(): Subscription
    {
        return app(StripeSyncService::class)->syncSubscription(
            StripeSubscription::constructFrom($this->stripeSubscriptionData())
        );
    }

    public function test_it_creates_a_checkout_session(): void
    {
        $sessions = Mockery::mock();
        $sessions->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (array $params) {
                // The redirect targets must be the frontend, not API routes.
                $this->assertStringStartsWith('https://app.test/', $params['success_url']);
                $this->assertStringContainsString(
                    'session_id={CHECKOUT_SESSION_ID}',
                    $params['success_url']
                );
                $this->assertSame('subscription', $params['mode']);
                $this->assertSame('cus_test123', $params['customer']);
                $this->assertSame('price_test123', $params['line_items'][0]['price']);

                // Needed by the webhook to resolve the owner.
                $this->assertArrayHasKey('metadata', $params['subscription_data']);

                return StripeCheckoutSession::constructFrom([
                    'id' => 'cs_test123',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test123',
                ]);
            });

        $this->mockStripe(['checkout' => (object) ['sessions' => $sessions]]);

        $this->actingAs($this->user)
            ->postJson('/api/stripe/checkout', ['price_id' => 'price_test123'])
            ->assertOk()
            ->assertJson([
                'checkout_session_id' => 'cs_test123',
                'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test123',
            ]);
    }

    public function test_it_rejects_an_unknown_price(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/stripe/checkout', ['price_id' => 'price_nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_id');
    }

    public function test_it_rejects_an_inactive_price(): void
    {
        $this->price->update(['active' => false]);

        $this->actingAs($this->user)
            ->postJson('/api/stripe/checkout', ['price_id' => 'price_test123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price_id');
    }

    public function test_it_refuses_a_second_subscription(): void
    {
        $this->existingSubscription();

        // Previously a double click produced two live subscriptions.
        $this->actingAs($this->user)
            ->postJson('/api/stripe/checkout', ['price_id' => 'price_test456'])
            ->assertStatus(409);
    }

    public function test_it_changes_the_plan_and_syncs_locally(): void
    {
        $local = $this->existingSubscription();
        $this->assertSame($this->price->id, $local->price_id);

        $subscriptions = Mockery::mock();
        $subscriptions->shouldReceive('retrieve')
            ->once()
            ->with('sub_test123')
            ->andReturn(StripeSubscription::constructFrom($this->stripeSubscriptionData()));

        $subscriptions->shouldReceive('update')
            ->once()
            ->andReturnUsing(function (string $id, array $params) {
                $this->assertSame('si_test123', $params['items'][0]['id']);
                $this->assertSame('price_test456', $params['items'][0]['price']);
                $this->assertSame('create_prorations', $params['proration_behavior']);

                return StripeSubscription::constructFrom(
                    $this->stripeSubscriptionData('price_test456')
                );
            });

        $this->mockStripe(['subscriptions' => $subscriptions]);

        $this->actingAs($this->user)
            ->postJson('/api/subscription/change-plan', ['price_id' => 'price_test456'])
            ->assertOk()
            ->assertJsonPath('subscription.stripe_price_id', 'price_test456');

        $this->assertSame($this->otherPrice->id, $local->fresh()->price_id);
    }

    public function test_it_refuses_a_plan_change_to_the_same_price(): void
    {
        $this->existingSubscription();

        $this->actingAs($this->user)
            ->postJson('/api/subscription/change-plan', ['price_id' => 'price_test123'])
            ->assertStatus(422);
    }

    public function test_it_cancels_at_period_end_without_marking_canceled(): void
    {
        $this->existingSubscription();

        $data = $this->stripeSubscriptionData();
        $data['cancel_at_period_end'] = true;
        $data['canceled_at'] = 1750000500;

        $subscriptions = Mockery::mock();
        $subscriptions->shouldReceive('update')
            ->once()
            ->with('sub_test123', ['cancel_at_period_end' => true])
            ->andReturn(StripeSubscription::constructFrom($data));

        $this->mockStripe(['subscriptions' => $subscriptions]);

        $this->actingAs($this->user)
            ->postJson('/api/subscription/cancel')
            ->assertOk()
            // Still active until the period actually ends.
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.cancel_at_period_end', true);
    }

    public function test_it_rejects_a_checkout_session_belonging_to_another_customer(): void
    {
        $sessions = Mockery::mock();
        $sessions->shouldReceive('retrieve')->once()->andReturn(
            StripeCheckoutSession::constructFrom([
                'id' => 'cs_other',
                'object' => 'checkout.session',
                'mode' => 'subscription',
                'customer' => 'cus_someoneelse',
                'status' => 'complete',
                'payment_status' => 'paid',
            ])
        );

        $this->mockStripe(['checkout' => (object) ['sessions' => $sessions]]);

        $this->actingAs($this->user)
            ->getJson('/api/subscription/success?session_id=cs_other')
            ->assertStatus(403);
    }

    public function test_it_confirms_a_successful_checkout(): void
    {
        $sessions = Mockery::mock();
        $sessions->shouldReceive('retrieve')->once()->andReturn(
            StripeCheckoutSession::constructFrom([
                'id' => 'cs_test123',
                'object' => 'checkout.session',
                'mode' => 'subscription',
                'customer' => 'cus_test123',
                'status' => 'complete',
                'payment_status' => 'paid',
                'subscription' => $this->stripeSubscriptionData(),
            ])
        );

        $this->mockStripe(['checkout' => (object) ['sessions' => $sessions]]);

        $this->actingAs($this->user)
            ->getJson('/api/subscription/success?session_id=cs_test123')
            ->assertOk()
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.stripe_subscription_id', 'sub_test123');

        $this->assertSame('cs_test123', Subscription::first()->stripe_checkout_session_id);
    }

    public function test_it_reports_no_subscription(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/subscription')
            ->assertStatus(404);
    }

    /**
     * Swap the container's StripeClient for a stub exposing only the services
     * the test needs.
     */
    private function mockStripe(array $services): void
    {
        $client = Mockery::mock(StripeClient::class);

        foreach ($services as $name => $service) {
            $client->{$name} = $service;
        }

        $this->app->instance(StripeClient::class, $client);
    }
}
