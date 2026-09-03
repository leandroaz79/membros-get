<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceCheckoutSession extends Model
{
    protected $fillable = [
        'tenant_id',
        'commerce_cart_id',
        'session_token',
        'order_id',
        'amount',
        'currency',
        'customer',
        'line_items',
        'metadata',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'customer' => 'array',
            'line_items' => 'array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(CommerceCart::class, 'commerce_cart_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
