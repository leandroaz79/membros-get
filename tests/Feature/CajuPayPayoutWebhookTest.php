<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\CommissionEntry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\PayoutRequestAllocation;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class CajuPayPayoutWebhookTest extends TestCase
{
    private const WEBHOOK_SECRET = 'cwhsec_testsecret123456789012345678901234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_payout_paid_webhook_completes_awaiting_payout(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
            'pix_key' => 'partner@test.com',
            'pix_key_type' => 'email',
        ]);

        $this->seedCajupayCredential(1);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'product_id' => $this->createTestProduct()->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
        ]);

        $entry = CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $partner->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
            'gross_amount' => 100,
            'gateway_fee_amount' => 0,
            'net_amount' => 100,
            'commission_percent' => 10,
            'commission_amount' => 10,
            'amount_paid' => 0,
            'status' => CommissionEntry::STATUS_RESERVED,
            'payment_method' => 'pix',
            'available_at' => now(),
        ]);

        $payout = PayoutRequest::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => 'webhook-paid-test',
            'user_id' => $partner->id,
            'tenant_id' => 1,
            'wallet_bucket' => 'pix',
            'amount_cents' => 1000,
            'status' => PayoutRequest::STATUS_AWAITING_PAYOUT,
            'pix_key' => 'partner@test.com',
            'pix_key_type' => 'email',
            'cajupay_payout_id' => 'payout-wh-paid-1',
            'cajupay_status' => 'pending',
            'requested_at' => now(),
        ]);

        $entry->update(['payout_request_id' => $payout->id]);

        PayoutRequestAllocation::create([
            'payout_request_id' => $payout->id,
            'commission_entry_id' => $entry->id,
            'amount' => 10.0,
        ]);

        $payload = [
            'type' => 'payout.paid',
            'data' => [
                'object' => [
                    'id' => 'payout-wh-paid-1',
                    'status' => 'paid',
                    'amount_cents' => 1000,
                ],
            ],
        ];

        $response = $this->postSignedWebhook($payload, 'payout.paid');

        $response->assertOk();
        $response->assertJsonPath('received', true);

        $payout->refresh();
        $this->assertEquals(PayoutRequest::STATUS_COMPLETED, $payout->status);
        $this->assertEquals('paid', $payout->cajupay_status);
        $this->assertNotNull($payout->completed_at);

        $entry->refresh();
        $this->assertEquals(CommissionEntry::STATUS_PAID, $entry->status);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $partner->id,
            'type' => 'debit',
            'source' => 'payout',
        ]);
    }

    public function test_payout_failed_webhook_marks_payout_failed_and_releases_reservation(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
            'pix_key' => 'fail@test.com',
            'pix_key_type' => 'email',
        ]);

        $this->seedCajupayCredential(1);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'product_id' => $this->createTestProduct()->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer-fail@test.com',
        ]);

        $entry = CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $partner->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
            'gross_amount' => 100,
            'gateway_fee_amount' => 0,
            'net_amount' => 100,
            'commission_percent' => 10,
            'commission_amount' => 10,
            'amount_paid' => 0,
            'status' => CommissionEntry::STATUS_RESERVED,
            'payment_method' => 'pix',
            'available_at' => now(),
        ]);

        $payout = PayoutRequest::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => 'webhook-failed-test',
            'user_id' => $partner->id,
            'tenant_id' => 1,
            'wallet_bucket' => 'pix',
            'amount_cents' => 1000,
            'status' => PayoutRequest::STATUS_AWAITING_PAYOUT,
            'pix_key' => 'fail@test.com',
            'pix_key_type' => 'email',
            'cajupay_payout_id' => 'payout-wh-failed-1',
            'cajupay_status' => 'pending',
            'requested_at' => now(),
        ]);

        $entry->update(['payout_request_id' => $payout->id]);

        $payload = [
            'type' => 'payout.failed',
            'data' => [
                'object' => [
                    'id' => 'payout-wh-failed-1',
                    'status' => 'failed',
                    'failure_reason' => 'Chave PIX inválida',
                ],
            ],
        ];

        $response = $this->postSignedWebhook($payload, 'payout.failed');

        $response->assertOk();
        $response->assertJsonPath('received', true);

        $payout->refresh();
        $this->assertEquals(PayoutRequest::STATUS_FAILED, $payout->status);
        $this->assertStringContainsString('Chave PIX inválida', (string) $payout->failure_reason);

        $entry->refresh();
        $this->assertEquals(CommissionEntry::STATUS_AVAILABLE, $entry->status);
        $this->assertNull($entry->payout_request_id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedWebhook(array $payload, string $eventType): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, self::WEBHOOK_SECRET);

        return $this->postJson(route('webhooks.cajupay'), $payload, [
            'X-CajuPay-Event' => $eventType,
            'X-CajuPay-Timestamp' => $ts,
            'X-CajuPay-Signature' => 't='.$ts.',v1='.$sig,
        ]);
    }

    private function seedCajupayCredential(int $tenantId): void
    {
        GatewayCredential::create([
            'tenant_id' => $tenantId,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
            'credentials' => Crypt::encryptString(json_encode([
                'public_key' => 'pk_test',
                'secret_key' => 'sk_test',
                'webhook_signing_secret' => self::WEBHOOK_SECRET,
            ])),
        ]);
    }
}
