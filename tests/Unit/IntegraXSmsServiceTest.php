<?php

namespace Tests\Unit;

use App\Jobs\IntegraXSendSmsJob;
use App\Models\IntegraxConnection;
use App\Services\IntegraX\IntegraXSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegraXSmsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_now_normalizes_brazil_phone_and_posts_to_api(): void
    {
        Http::fake([
            'sms.aresfun.com/*' => Http::response(['ok' => true], 200),
        ]);

        $connection = IntegraxConnection::create([
            'tenant_id' => 1,
            'api_token' => 'test-token-abc',
            'is_active' => true,
        ]);

        $service = app(IntegraXSmsService::class);
        $result = $service->sendNow($connection, '(11) 99999-8888', 'Mensagem de teste');

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://sms.aresfun.com/v1/integration/test-token-abc/send-sms'
                && ($body['to'][0] ?? null) === '5511999998888'
                && ($body['message'] ?? null) === 'Mensagem de teste';
        });
    }

    public function test_queue_message_rejects_invalid_phone(): void
    {
        Queue::fake();

        IntegraxConnection::create([
            'tenant_id' => 1,
            'api_token' => 'token',
            'is_active' => true,
        ]);

        $service = app(IntegraXSmsService::class);
        $queued = $service->queueMessage(1, '123', 'Oi');

        $this->assertFalse($queued);
        Queue::assertNothingPushed();
    }

    public function test_queue_message_dispatches_job_for_valid_input(): void
    {
        Queue::fake();

        IntegraxConnection::create([
            'tenant_id' => 1,
            'api_token' => 'token',
            'is_active' => true,
        ]);

        $service = app(IntegraXSmsService::class);
        $queued = $service->queueMessage(1, '11988887777', 'Mensagem curta');

        $this->assertTrue($queued);
        Queue::assertPushed(IntegraXSendSmsJob::class);
    }
}
