<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'product_id', 'stripe_price_id', 'amount', 'currency',
        'type', 'interval', 'interval_count', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'amount' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}
