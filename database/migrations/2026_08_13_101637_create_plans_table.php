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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            $table->text('description')->nullable();

            $table->string('stripe_product_id')
                ->unique();

            $table->string('stripe_price_id')
                ->unique();

            // Amount in smallest currency unit
            // Example: 1000 = $10.00 USD
            $table->unsignedBigInteger('amount');

            $table->string('currency', 3);

            $table->string('interval');

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
        Schema::dropIfExists('plans');
    }
};
