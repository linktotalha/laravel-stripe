<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('stripe_subscription_id')
                ->unique();

            $table->string('stripe_customer_id');

            $table->string('stripe_price_id');

            $table->string('status');

            $table->timestamp('current_period_start')
                ->nullable();

            $table->timestamp('current_period_end')
                ->nullable();

            $table->boolean('cancel_at_period_end')
                ->default(false);

            $table->timestamp('canceled_at')
                ->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
