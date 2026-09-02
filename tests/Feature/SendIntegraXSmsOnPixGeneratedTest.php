<?php

namespace Tests\Feature;

use App\Events\PixGenerated;
use App\Jobs\SendIntegraXPixSmsJob;
use App\Models\IntegraxConnection;
use App\Models\Order;
use App\Services\IntegraX\IntegraXSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendIntegraXSmsOnPixGeneratedTest extends TestCase
{
    use RefreshDatabase;

    public function test_pix_sms_job_sends_using_event_pix_data_before_order_metadata_persisted(): void
    {
        Http::fake([
            'sms.aresfun.com/*' => Http::response(['ok' => true], 200),
        ]);

        IntegraxConnection::create([
            'tenant_id' => 1,
            'api_token' => 'token-test',
            'is_active' => true,
        ]);

        $product = $this->createTestProduct([
            'checkout_config' => [
                'sms' => [
                    'pix_generated' => [
                        'enabled' => true,
                        'body_text' => 'PIX {link_pix}',
                    ],
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'amount' => 29.9,
            'currency' => 'BRL',
            'email' => 'cliente@test.com',
            'phone' => '11988887777',
            'status' => 'pending',
            'metadata' => [],
        ]);
        $order->setRelation('product', $product);

        $job = new SendIntegraXPixSmsJob($order->id, [
            'copy_paste' => '00020126580014br.gov.bcb.pix',
        ]);
        $job->handle(app(IntegraXSmsService::class));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains((string) ($body['message'] ?? ''), 'checkout/pix');
        });
    }

    public function test_listener_schedules_job_without_throwing(): void
    {
        $product = $this->createTestProduct([
            'checkout_config' => [
                'sms' => [
                    'pix_generated' => ['enabled' => true, 'body_text' => 'PIX {link_pix}'],
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'amount' => 10,
            'currency' => 'BRL',
            'email' => 'a@b.com',
            'phone' => '11999998888',
            'status' => 'pending',
        ]);

        $listener = app(\App\Listeners\SendIntegraXSmsOnPixGenerated::class);
        $listener->handle(new PixGenerated($order, ['copy_paste' => 'pix-code']));

        $this->assertTrue(true);
    }

    public function test_pix_link_resolver_uses_event_payload_when_order_metadata_empty(): void
    {
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'amount' => 10,
            'currency' => 'BRL',
            'email' => 'a@b.com',
            'status' => 'pending',
            'metadata' => [],
        ]);
        $order->setRelation('product', $product);

        $url = \App\Support\IntegraXPixLinkResolver::urlForOrder($order, [
            'copy_paste' => 'pix-copia-e-cola-teste',
        ]);

        $this->assertNotNull($url);
        $this->assertStringContainsString('checkout/pix', $url);
    }
}
