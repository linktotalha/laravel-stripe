<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Product extends Model
{
    /**
     * These must match the products table. The previous list was copied from
     * Price, so name/description/stripe_product_id were silently dropped on
     * mass assignment and the insert failed on the NOT NULL name column.
     */
    protected $fillable = [
        'name',
        'description',
        'stripe_product_id',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    public function subscriptions(): HasManyThrough
    {
        return $this->hasManyThrough(Subscription::class, Price::class);
    }
}
