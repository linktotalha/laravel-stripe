<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Webhook;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_testsecret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => self::SECRET]);

        $user = User::create([
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

        Price::create([
            'product_id' => $product->id,
            'stripe_price_id' => 'price_test123',
            'amount' => 1000,
            'currency' => 'usd',
            'interval' => 'month',
            'interval_count' => 1,
            'active' => true,
        ]);

        $this->user = $user;
    }

    private User $user;

    /**
     * Post a signed event the same way Stripe does.
     */
    private function postEvent(array $event, ?string $signature = null)
    {
        $payload = json_encode($event);

        // Must be current: constructEvent enforces a replay tolerance window.
        $timestamp = time();

        $signature ??= 't=' . $timestamp . ',v1=' . hash_hmac(
            'sha256',
            $timestamp . '.' . $payload,
            self::SECRET
        );

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );
    }

    private function subscriptionEvent(string $id, string $type, string $status = 'active'): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'created' => 1750000000,
            'data' => [
                'object' => [
                    'id' => 'sub_test123',
                    'object' => 'subscription',
                    'customer' => 'cus_test123',
                    'status' => $status,
                    'cancel_at_period_end' => false,
                    'metadata' => [],
                    'items' => [
                        'object' => 'list',
                        'data' => [[
                            'id' => 'si_test123',
                            'object' => 'subscription_item',
                            'price' => ['id' => 'price_test123', 'object' => 'price'],
                            'current_period_start' => 1750000000,
                            'current_period_end' => 1752678400,
                        ]],
                    ],
                ],
            ],
        ];
    }

    public function test_it_rejects_an_invalid_signature(): void
    {
        $response = $this->postEvent(
            $this->subscriptionEvent('evt_1', 'customer.subscription.created'),
            't=1750000000,v1=deadbeef'
        );

        $response->assertStatus(400);
        $this->assertSame(0, Subscription::count());
    }

    public function test_it_syncs_a_created_subscription(): void
    {
        $response = $this->postEvent(
            $this->subscriptionEvent('evt_2', 'customer.subscription.created')
        );

        $response->assertOk()->assertJson(['received' => true]);

        $subscription = Subscription::first();
        $this->assertNotNull($subscription);
        $this->assertSame('active', $subscription->status);
        $this->assertSame(1752678400, $subscription->current_period_end->getTimestamp());

        $this->assertNotNull(
            StripeEvent::where('stripe_event_id', 'evt_2')->first()->processed_at
        );
    }

    public function test_it_syncs_an_updated_subscription(): void
    {
        // customer.subscription.updated was previously unhandled, so dunning
        // and portal-side plan changes never reached the database.
        $this->postEvent($this->subscriptionEvent('evt_3', 'customer.subscription.created'));

        $this->postEvent(
            $this->subscriptionEvent('evt_4', 'customer.subscription.updated', 'past_due')
        )->assertOk();

        $this->assertSame('past_due', Subscription::first()->status);
    }

    public function test_it_syncs_a_deleted_subscription(): void
    {
        $this->postEvent($this->subscriptionEvent('evt_5', 'customer.subscription.created'));

        $this->postEvent(
            $this->subscriptionEvent('evt_6', 'customer.subscription.deleted', 'canceled')
        )->assertOk();

        $this->assertSame('canceled', Subscription::first()->status);
    }

    public function test_it_ignores_a_replayed_event(): void
    {
        $event = $this->subscriptionEvent('evt_7', 'customer.subscription.updated', 'active');

        $this->postEvent($event)->assertOk();

        // Stripe retries the same event id; the second delivery must be a no-op.
        $this->postEvent($event)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, StripeEvent::where('stripe_event_id', 'evt_7')->count());
    }

    public function test_it_acknowledges_an_unhandled_event_type(): void
    {
        $this->postEvent([
            'id' => 'evt_8',
            'object' => 'event',
            'type' => 'customer.updated',
            'created' => 1750000000,
            'data' => ['object' => ['id' => 'cus_test123', 'object' => 'customer']],
        ])->assertOk()->assertJson(['received' => true]);
    }
}
