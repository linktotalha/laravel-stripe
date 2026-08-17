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
        Schema::create('prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('stripe_price_id')
                ->unique();

            // Stripe amount in smallest currency unit
            // Example: 1000 = $10.00 USD
            $table->unsignedBigInteger('amount');

            $table->string('currency', 3);

            // 'recurring' or 'one_time'
            $table->string('type')->default('recurring');

            $table->string('interval');

            $table->unsignedInteger('interval_count')
                ->default(1);

            $table->boolean('active')
                ->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
