<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Price extends Model
{
    protected $fillable = [
        'product_id',
        'stripe_price_id',
        'amount',
        'currency',
        'interval',
        'interval_count',
        'active',
    ];

    protected $casts = [
        'amount' => 'integer',
        'interval_count' => 'integer',
        'active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}
