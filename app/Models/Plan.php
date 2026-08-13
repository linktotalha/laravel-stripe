<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'stripe_product_id',
        'stripe_price_id',
        'amount',
        'currency',
        'interval',
        'active',
    ];

    protected $casts = [
        'amount' => 'integer',
        'active' => 'boolean',
    ];
}
