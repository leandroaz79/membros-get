<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Jobs\SendCampaignEmailJob;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignSend;
use App\Models\Order;
use App\Models\User;
use App\Services\EmailCampaignRecipientsService;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class EmailCampaignControlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_user_can_pause_sending_campaign(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $campaign = $this->createCampaign($user->tenant_id, EmailCampaign::STATUS_SENDING);

        $this->actingAs($user)
            ->post("/email-marketing/{$campaign->id}/pause")
            ->assertRedirect(route('email-marketing.index'));

        $this->assertSame(EmailCampaign::STATUS_PAUSED, $campaign->fresh()->status);
        $this->assertNotNull($campaign->fresh()->paused_at);
    }

    public function test_user_can_cancel_sending_campaign(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $campaign = $this->createCampaign($user->tenant_id, EmailCampaign::STATUS_SENDING);

        $this->actingAs($user)
            ->post("/email-marketing/{$campaign->id}/cancel")
            ->assertRedirect(route('email-marketing.index'));

        $this->assertSame(EmailCampaign::STATUS_CANCELLED, $campaign->fresh()->status);
    }

    public function test_user_can_resume_paused_campaign(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $campaign = $this->createCampaign($user->tenant_id, EmailCampaign::STATUS_PAUSED);

        $this->actingAs($user)
            ->post("/email-marketing/{$campaign->id}/resume")
            ->assertRedirect(route('email-marketing.index'));

        $fresh = $campaign->fresh();
        $this->assertSame(EmailCampaign::STATUS_SENDING, $fresh->status);
        $this->assertNull($fresh->paused_at);
    }

    public function test_failed_recipient_is_not_retried_automatically(): void
    {
        $product = $this->createTestProduct();
        $campaign = $this->createCampaign($product->tenant_id, EmailCampaign::STATUS_SENDING);

        Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'cliente@example.com',
            'gateway' => 'cajupay',
        ]);

        EmailCampaignSend::create([
            'email_campaign_id' => $campaign->id,
            'email' => 'cliente@example.com',
            'status' => EmailCampaignSend::STATUS_FAILED,
            'error_message' => 'Rate limit exceeded',
        ]);

        $recipients = app(EmailCampaignRecipientsService::class)
            ->getNextRecipientsForCampaign($campaign->fresh(), 30);

        $this->assertCount(0, $recipients);
    }

    public function test_send_job_auto_pauses_on_rate_limit_and_records_failure(): void
    {
        $campaign = $this->createCampaign(1, EmailCampaign::STATUS_SENDING);

        $pending = Mockery::mock();
        $pending->shouldReceive('send')->once()->andThrow(new \RuntimeException('429 Too Many Requests'));

        $mailer = Mockery::mock(\Illuminate\Mail\Mailer::class);
        $mailer->shouldReceive('to')->once()->with('fail@example.com')->andReturn($pending);

        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);

        $job = new SendCampaignEmailJob($campaign->id, 'fail@example.com', null, 'Fail');
        $job->handle(app(\App\Services\TenantMailConfigService::class));

        $send = EmailCampaignSend::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('email', 'fail@example.com')
            ->first();

        $this->assertNotNull($send);
        $this->assertSame(EmailCampaignSend::STATUS_FAILED, $send->status);
        $this->assertSame(EmailCampaign::STATUS_PAUSED, $campaign->fresh()->status);
    }

    private function createCampaign(?int $tenantId, string $status): EmailCampaign
    {
        return EmailCampaign::create([
            'tenant_id' => $tenantId,
            'name' => 'Campanha teste',
            'subject' => 'Assunto',
            'body_html' => '<p>Olá {nome}</p>',
            'filter_config' => ['all_customers' => true],
            'status' => $status,
            'total_recipients' => 1,
            'sent_count' => 0,
        ]);
    }
}
