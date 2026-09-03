<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayFeeSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'gateway_slug',
        'method',
        'percent',
        'fixed_cents',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'decimal:4',
            'fixed_cents' => 'integer',
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
    }

    /**
     * @return array{percent: float, fixed_cents: int}
     */
    public static function defaultsFor(string $gatewaySlug, string $method): array
    {
        $slug = strtolower(trim($gatewaySlug));
        $gatewayDefaults = config('commissions.gateway_default_fees.'.$slug.'.'.$method);
        if (is_array($gatewayDefaults)) {
            return [
                'percent' => (float) ($gatewayDefaults['percent'] ?? 0),
                'fixed_cents' => (int) ($gatewayDefaults['fixed_cents'] ?? 0),
            ];
        }

        $defaults = config('commissions.default_gateway_fees.'.$method, ['percent' => 0, 'fixed_cents' => 0]);

        return [
            'percent' => (float) ($defaults['percent'] ?? 0),
            'fixed_cents' => (int) ($defaults['fixed_cents'] ?? 0),
        ];
    }
}
