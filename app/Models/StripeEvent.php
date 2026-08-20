<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeEvent extends Model
{
    protected $fillable = [
        'stripe_event_id',
        'type',
        'event_created_at',
        'processed_at',
        'error',
    ];

    protected $casts = [
        'event_created_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
