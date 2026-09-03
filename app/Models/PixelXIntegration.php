<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PixelXIntegration extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'url',
        'token',
        'events',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'token' => 'encrypted',
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }

        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Check if this integration listens to the given event class.
     * Events are stored as slugs; translates FQCN → slug via webhook_events config.
     */
    public function listensTo(string $eventClass): bool
    {
        $events = $this->events ?? [];
        if (empty($events)) {
            return false;
        }

        // config('webhook_events.event_slugs') maps FQCN => slug
        $slug = config('webhook_events.event_slugs')[$eventClass] ?? null;
        if ($slug === null) {
            return false;
        }

        return in_array($slug, $events, true);
    }

    /**
     * Check if this integration should fire for a given product.
     *
     * @param  int|string|null  $productId  Product ID (int or UUID string)
     */
    public function shouldFireForProduct(mixed $productId): bool
    {
        if ($productId === null || $productId === '') {
            return true;
        }

        $productIds = $this->products()->pluck('products.id')->map(fn ($id) => (string) $id)->toArray();

        if (empty($productIds)) {
            return true;
        }

        return in_array((string) $productId, $productIds, true);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PixelXIntegrationLog::class)->orderByDesc('created_at');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'pixel_x_integration_product');
    }
}
