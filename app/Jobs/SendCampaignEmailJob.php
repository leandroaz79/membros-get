<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignSend;
use App\Services\TenantMailConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCampaignEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Pausa automática após N falhas consecutivas na campanha. */
    private const AUTO_PAUSE_FAILURE_THRESHOLD = 5;

    public function __construct(
        public int $emailCampaignId,
        public string $email,
        public ?int $userId,
        public string $name
    ) {}

    public function handle(TenantMailConfigService $mailConfig): void
    {
        $campaign = EmailCampaign::find($this->emailCampaignId);
        if (! $campaign || ! $campaign->isSending()) {
            return;
        }

        $existing = EmailCampaignSend::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('email', $this->email)
            ->first();

        if ($existing?->status === EmailCampaignSend::STATUS_SENT) {
            return;
        }

        $mailConfig->applyMailerConfigForTenant($campaign->tenant_id, [], null);

        $body = str_replace(
            ['{nome}', '{email}'],
            [e($this->name), e($this->email)],
            $campaign->body_html
        );

        try {
            Mail::mailer('smtp')->to($this->email)->send(new CampaignMail($campaign->subject, $body));
        } catch (\Throwable $e) {
            $this->recordFailure($campaign, $e);

            return;
        }

        EmailCampaignSend::updateOrCreate(
            [
                'email_campaign_id' => $campaign->id,
                'email' => $this->email,
            ],
            [
                'user_id' => $this->userId,
                'status' => EmailCampaignSend::STATUS_SENT,
                'error_message' => null,
                'sent_at' => now(),
            ]
        );

        if ($existing?->status !== EmailCampaignSend::STATUS_SENT) {
            $campaign->increment('sent_count');
        }

        $campaign->update(['last_error' => null]);
    }

    private function recordFailure(EmailCampaign $campaign, \Throwable $e): void
    {
        $message = $e->getMessage();

        Log::warning('SendCampaignEmailJob: falha ao enviar.', [
            'campaign_id' => $this->emailCampaignId,
            'email' => $this->email,
            'message' => $message,
        ]);

        EmailCampaignSend::updateOrCreate(
            [
                'email_campaign_id' => $campaign->id,
                'email' => $this->email,
            ],
            [
                'user_id' => $this->userId,
                'status' => EmailCampaignSend::STATUS_FAILED,
                'error_message' => mb_substr($message, 0, 2000),
                'sent_at' => null,
            ]
        );

        $recentFailures = EmailCampaignSend::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('status', EmailCampaignSend::STATUS_FAILED)
            ->where('updated_at', '>=', now()->subMinutes(10))
            ->count();

        $shouldAutoPause = $this->isRateLimitError($e)
            || $recentFailures >= self::AUTO_PAUSE_FAILURE_THRESHOLD;

        if ($shouldAutoPause && $campaign->isSending()) {
            $reason = $this->isRateLimitError($e)
                ? 'Limite de envio do provedor atingido. Campanha pausada automaticamente.'
                : 'Muitas falhas consecutivas. Campanha pausada automaticamente.';

            $campaign->update([
                'status' => EmailCampaign::STATUS_PAUSED,
                'paused_at' => now(),
                'last_error' => $reason . ' Detalhe: ' . mb_substr($message, 0, 500),
            ]);
        } else {
            $campaign->update([
                'last_error' => mb_substr($message, 0, 500),
            ]);
        }
    }

    private function isRateLimitError(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        foreach (['rate limit', 'too many', '429', 'throttl', '550 5.4.6', '451 4.7.1', '421 4.7.0', 'exceeded'] as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }

        return false;
    }
}
