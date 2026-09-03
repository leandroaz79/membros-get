<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceCartLine extends Model
{
    protected $fillable = [
        'commerce_cart_id',
        'product_id',
        'product_offer_id',
        'subscription_plan_id',
        'quantity',
        'unit_amount',
        'metadata',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'unit_amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(CommerceCart::class, 'commerce_cart_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
