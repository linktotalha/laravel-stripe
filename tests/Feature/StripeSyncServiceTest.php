<?php

namespace Tests\Feature;

use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\StripeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Invoice as StripeInvoice;
use Stripe\Subscription as StripeSubscription;
use Tests\TestCase;

/**
 * Covers the field moves in Stripe API 2025-04-30.basil, which stripe-php v21
 * pins past: subscription periods live on the item, and the invoice's
 * subscription id lives under parent.subscription_details.
 */
class StripeSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private StripeSyncService $sync;

    private User $user;

    private Price $price;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sync = app(StripeSyncService::class);

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
    }

    private function stripeSubscription(array $overrides = []): StripeSubscription
    {
        return StripeSubscription::constructFrom(array_merge([
            'id' => 'sub_test123',
            'object' => 'subscription',
            'customer' => 'cus_test123',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'ended_at' => null,
            'trial_start' => null,
            'trial_end' => null,
            'metadata' => [],
            'items' => [
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'si_test123',
                        'object' => 'subscription_item',
                        'price' => ['id' => 'price_test123', 'object' => 'price'],
                        // Periods live HERE, not on the subscription.
                        'current_period_start' => 1750000000,
                        'current_period_end' => 1752678400,
                    ],
                ],
            ],
        ], $overrides));
    }

    public function test_it_reads_the_billing_period_from_the_subscription_item(): void
    {
        $subscription = $this->sync->syncSubscription($this->stripeSubscription());

        $this->assertNotNull($subscription);
        $this->assertSame($this->user->id, $subscription->user_id);
        $this->assertSame($this->price->id, $subscription->price_id);
        $this->assertSame('active', $subscription->status);

        // Would be null if it still read Subscription::$current_period_start.
        $this->assertNotNull($subscription->current_period_start);
        $this->assertSame(
            1750000000,
            $subscription->current_period_start->getTimestamp()
        );
        $this->assertSame(
            1752678400,
            $subscription->current_period_end->getTimestamp()
        );
    }

    public function test_it_resolves_the_user_by_stripe_customer_id_without_metadata(): void
    {
        // A subscription created in the Stripe dashboard carries no metadata.
        $subscription = $this->sync->syncSubscription($this->stripeSubscription());

        $this->assertNotNull($subscription);
        $this->assertSame($this->user->id, $subscription->user_id);
    }

    public function test_it_is_idempotent_on_repeated_sync(): void
    {
        $this->sync->syncSubscription($this->stripeSubscription());
        $this->sync->syncSubscription($this->stripeSubscription(['status' => 'past_due']));

        $this->assertSame(1, Subscription::count());
        $this->assertSame('past_due', Subscription::first()->status);
    }

    public function test_it_does_not_reopen_a_canceled_subscription(): void
    {
        $this->sync->syncSubscription($this->stripeSubscription([
            'status' => 'canceled',
            'ended_at' => 1752678400,
        ]));

        // A stale "active" event arriving after cancellation must be ignored.
        $this->sync->syncSubscription($this->stripeSubscription(['status' => 'active']));

        $this->assertSame('canceled', Subscription::first()->status);
    }

    public function test_it_extracts_the_subscription_id_from_the_invoice_parent(): void
    {
        $invoice = StripeInvoice::constructFrom([
            'id' => 'in_test123',
            'object' => 'invoice',
            'parent' => [
                'type' => 'subscription_details',
                'subscription_details' => [
                    'subscription' => 'sub_test123',
                ],
            ],
        ]);

        // Invoice::$subscription was removed; reading it returns null.
        $this->assertNull($invoice->subscription ?? null);

        $this->assertSame('sub_test123', $this->sync->subscriptionIdFromInvoice($invoice));
    }

    public function test_it_returns_null_for_a_one_off_invoice(): void
    {
        $invoice = StripeInvoice::constructFrom([
            'id' => 'in_test456',
            'object' => 'invoice',
            'parent' => null,
        ]);

        $this->assertNull($this->sync->subscriptionIdFromInvoice($invoice));
    }

    public function test_it_persists_every_invoice_amount_column(): void
    {
        $subscription = $this->sync->syncSubscription($this->stripeSubscription());

        $invoice = $this->sync->syncInvoice(
            StripeInvoice::constructFrom([
                'id' => 'in_test789',
                'object' => 'invoice',
                'customer' => 'cus_test123',
                'status' => 'paid',
                'amount_due' => 1000,
                'amount_paid' => 1000,
                'amount_remaining' => 0,
                'currency' => 'usd',
                'created' => 1750000000,
                'due_date' => null,
                'status_transitions' => ['paid_at' => 1750000100],
                'hosted_invoice_url' => 'https://invoice.stripe.com/test',
                'invoice_pdf' => 'https://invoice.stripe.com/test.pdf',
            ]),
            $subscription
        );

        // These stayed 0 while the writer used a non-existent "amount" key.
        $this->assertSame(1000, $invoice->amount_due);
        $this->assertSame(1000, $invoice->amount_paid);
        $this->assertSame(0, $invoice->amount_remaining);
        $this->assertSame('usd', $invoice->currency);
        $this->assertSame(1750000100, $invoice->paid_at->getTimestamp());
        $this->assertSame('https://invoice.stripe.com/test', $invoice->hosted_invoice_url);
        $this->assertSame($subscription->id, $invoice->subscription_id);
        $this->assertSame($this->user->id, $invoice->user_id);
    }

    public function test_it_skips_a_subscription_whose_price_is_unknown_locally(): void
    {
        $subscription = $this->sync->syncSubscription($this->stripeSubscription([
            'items' => [
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'si_other',
                        'object' => 'subscription_item',
                        'price' => ['id' => 'price_unknown', 'object' => 'price'],
                        'current_period_start' => 1750000000,
                        'current_period_end' => 1752678400,
                    ],
                ],
            ],
        ]));

        $this->assertNull($subscription);
        $this->assertSame(0, Subscription::count());
    }
}
