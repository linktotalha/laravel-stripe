<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'user_id',
        'subscription_id',
        'stripe_invoice_id',
        'stripe_customer_id',
        'status',
        'amount_due',
        'amount_paid',
        'amount_remaining',
        'currency',
        'invoice_created_at',
        'due_date',
        'paid_at',
        'hosted_invoice_url',
        'invoice_pdf',
        'metadata',
    ];

    protected $casts = [
        'amount_due' => 'integer',
        'amount_paid' => 'integer',
        'amount_remaining' => 'integer',
        'invoice_created_at' => 'datetime',
        'due_date' => 'datetime',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
