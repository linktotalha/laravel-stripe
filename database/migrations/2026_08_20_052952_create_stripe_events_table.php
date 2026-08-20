<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stripe retries a webhook until it gets a 2xx, so the same event id can
     * arrive several times. This table makes handling idempotent.
     */
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();

            $table->string('stripe_event_id')->unique();

            $table->string('type')->index();

            // When Stripe created the event, not when we received it.
            $table->timestamp('event_created_at')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->text('error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
