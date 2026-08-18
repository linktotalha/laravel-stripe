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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('subscription_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('stripe_invoice_id')
                ->unique();

            $table->string('stripe_customer_id');

            $table->string('status')->nullable();

            $table->unsignedBigInteger('amount_due')
                ->default(0);

            $table->unsignedBigInteger('amount_paid')
                ->default(0);

            $table->unsignedBigInteger('amount_remaining')
                ->default(0);

            $table->string('currency', 3);

            $table->timestamp('invoice_created_at')
                ->nullable();

            $table->timestamp('due_date')
                ->nullable();

            $table->timestamp('paid_at')
                ->nullable();

            $table->string('hosted_invoice_url')
                ->nullable();

            $table->string('invoice_pdf')
                ->nullable();

            $table->json('metadata')
                ->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
