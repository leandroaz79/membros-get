<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorefrontDomain extends Model
{
    protected $fillable = [
        'tenant_id',
        'host',
        'plugin_slug',
        'is_verified',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
        ];
    }

    public static function normalizeHost(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $host = strtolower(trim($value));
        if ($host === '') {
            return null;
        }
        $host = (string) preg_replace('#^https?://#', '', $host);
        $host = explode('/', $host)[0] ?? '';
        $host = rtrim(trim($host), '.');
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host !== '' ? $host : null;
    }
}
