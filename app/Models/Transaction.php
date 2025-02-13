<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'order_id',
        'payment_provider',
        'stauts',
        'response_data',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
