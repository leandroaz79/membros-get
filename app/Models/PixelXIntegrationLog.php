<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PixelXIntegrationLog extends Model
{
    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'pixel_x_integration_id',
        'event',
        'event_label',
        'request_payload',
        'response_status',
        'response_body',
        'success',
        'error_message',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'success' => 'boolean',
        ];
    }

    public function pixelXIntegration(): BelongsTo
    {
        return $this->belongsTo(PixelXIntegration::class);
    }
}
