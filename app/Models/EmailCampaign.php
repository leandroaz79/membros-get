<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENDING = 'sending';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'name',
        'subject',
        'body_html',
        'filter_config',
        'status',
        'total_recipients',
        'sent_count',
        'scheduled_at',
        'sent_at',
        'paused_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'filter_config' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'paused_at' => 'datetime',
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }

        return $query->where('tenant_id', $tenantId);
    }

    public function scopeSending($query)
    {
        return $query->where('status', self::STATUS_SENDING);
    }

    public function emailCampaignSends(): HasMany
    {
        return $this->hasMany(EmailCampaignSend::class, 'email_campaign_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSending(): bool
    {
        return $this->status === self::STATUS_SENDING;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function canPause(): bool
    {
        return $this->isSending();
    }

    public function canResume(): bool
    {
        return $this->isPaused();
    }

    public function canCancel(): bool
    {
        return $this->isSending() || $this->isPaused();
    }
}
