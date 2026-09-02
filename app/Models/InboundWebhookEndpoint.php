<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboundWebhookEndpoint extends Model
{
    protected $table = 'inbound_webhook_endpoints';

    protected $fillable = [
        'tenant_id',
        'name',
        'is_active',
        'url_token',
        'product_id',
        'product_offer_id',
        'subscription_plan_id',
        'field_map',
        'signing_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'field_map' => 'array',
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public function normalizedFieldMap(): array
    {
        $map = $this->field_map;
        if (! is_array($map) || $map === []) {
            return [
                'email' => 'email',
                'name' => 'name',
                'cpf' => 'cpf',
                'phone' => 'phone',
                'external_id' => 'external_id',
            ];
        }

        $strict = (bool) ($map['_strict'] ?? false);
        $out = ['_strict' => $strict];
        foreach ($map as $internal => $path) {
            if ($internal === '_strict') {
                continue;
            }
            $k = is_string($internal) ? trim($internal) : '';
            if ($k === '') {
                continue;
            }
            if (is_string($path) && trim($path) !== '') {
                $out[$k] = trim($path);
            } elseif (is_array($path)) {
                $paths = array_values(array_filter($path, fn ($p) => is_string($p) && trim($p) !== ''));
                if ($paths !== []) {
                    $out[$k] = $paths;
                }
            }
        }

        if (! isset($out['email'])) {
            $out['email'] = 'email';
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  string|array<int, string>  $pathOrPaths
     */
    public static function resolvePayloadValue(array $payload, string|array $pathOrPaths): string
    {
        $paths = is_array($pathOrPaths) ? $pathOrPaths : [$pathOrPaths];
        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }
            $value = data_get($payload, trim($path));
            if ($value === null || $value === '') {
                continue;
            }
            if (is_scalar($value)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    public static function generateUrlToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
