<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegraxConnection extends Model
{
    protected $fillable = [
        'tenant_id',
        'api_token',
        'is_active',
        'last_tested_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }

        return $query->where('tenant_id', $tenantId);
    }

    public function isConfigured(): bool
    {
        $token = $this->api_token;

        return is_string($token) && trim($token) !== '';
    }
}
