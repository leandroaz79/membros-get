<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CajuPayWebhookBootstrapTest extends TestCase
{
    private function actingInfoprodutor(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);
    }

    public function test_gateway_update_registers_webhook_and_persists_signing_secret(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        Http::fake([
            '*/api/wallet/balance*' => Http::response(['balance_cents' => 0], 200),
            '*/api/webhooks/endpoints/register' => Http::response([
                'endpoint' => [
                    'id' => 'ep_new_001',
                    'url' => 'https://getfy.test/webhooks/gateways/cajupay',
                    'enabled' => true,
                    'event_types' => ['checkout.payment.*', 'pix.payment.*', 'payout.*'],
                ],
                'created' => true,
                'already_exists' => false,
                'signing_secret' => 'cwhsec_newsecret123456789012345678901234',
            ], 201),
        ]);

        $user = $this->actingInfoprodutor();

        $response = $this->actingAs($user)->putJson(route('gateways.update', ['slug' => 'cajupay']), [
            'public_key' => 'gpk_test_public',
            'secret_key' => 'gsk_test_secret',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('webhook_auto_configured', true);
        $response->assertJsonPath('webhook_signing_secret_set', true);

        $cred = GatewayCredential::forTenant(1)->where('gateway_slug', 'cajupay')->first();
        $this->assertNotNull($cred);
        $decrypted = $cred->getDecryptedCredentials();
        $this->assertSame('ep_new_001', $decrypted['webhook_endpoint_id'] ?? null);
        $this->assertSame('cwhsec_newsecret123456789012345678901234', $decrypted['webhook_signing_secret'] ?? null);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/webhooks/endpoints/register')) {
                return false;
            }
            $body = $request->data();

            return ($body['rotate_if_exists'] ?? null) === false
                && in_array('checkout.payment.*', $body['event_types'] ?? [], true)
                && in_array('pix.payment.*', $body['event_types'] ?? [], true)
                && in_array('payout.*', $body['event_types'] ?? [], true);
        });
    }

    public function test_gateway_update_rotates_secret_when_endpoint_exists_without_local_secret(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $registerCalls = 0;
        Http::fake(function ($request) use (&$registerCalls) {
            if (str_contains($request->url(), '/api/wallet/balance')) {
                return Http::response(['balance_cents' => 0], 200);
            }
            if (str_contains($request->url(), '/api/webhooks/endpoints/register')) {
                $registerCalls++;
                if ($registerCalls === 1) {
                    return Http::response([
                        'endpoint' => ['id' => 'ep_existing', 'url' => 'https://getfy.test/webhooks/gateways/cajupay'],
                        'created' => false,
                        'already_exists' => true,
                    ], 200);
                }

                return Http::response([
                    'endpoint' => ['id' => 'ep_existing', 'url' => 'https://getfy.test/webhooks/gateways/cajupay'],
                    'created' => false,
                    'already_exists' => true,
                    'signing_secret' => 'cwhsec_rotatedsecret123456789012345678901',
                ], 200);
            }

            return Http::response([], 404);
        });

        $user = $this->actingInfoprodutor();

        $response = $this->actingAs($user)->putJson(route('gateways.update', ['slug' => 'cajupay']), [
            'public_key' => 'gpk_test_public',
            'secret_key' => 'gsk_test_secret',
        ]);

        $response->assertOk();
        $response->assertJsonPath('webhook_auto_configured', true);

        $cred = GatewayCredential::forTenant(1)->where('gateway_slug', 'cajupay')->first();
        $decrypted = $cred->getDecryptedCredentials();
        $this->assertSame('cwhsec_rotatedsecret123456789012345678901', $decrypted['webhook_signing_secret'] ?? null);
        $this->assertSame(2, $registerCalls);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/webhooks/endpoints/register')) {
                return false;
            }
            $body = $request->data();

            return ($body['rotate_if_exists'] ?? null) === true;
        });
    }

    public function test_rotate_webhook_secret_endpoint_updates_credentials(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        Http::fake([
            '*/api/webhooks/endpoints/register' => Http::response([
                'endpoint' => ['id' => 'ep_existing', 'url' => 'https://getfy.test/webhooks/gateways/cajupay'],
                'created' => false,
                'already_exists' => true,
                'signing_secret' => 'cwhsec_manualrotate123456789012345678901',
            ], 200),
        ]);

        $cred = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
            'webhook_endpoint_id' => 'ep_existing',
            'webhook_signing_secret' => 'cwhsec_oldsecret123456789012345678901234',
        ]);
        $cred->save();

        $user = $this->actingInfoprodutor();

        $response = $this->actingAs($user)->postJson(route('gateways.cajupay.rotate-webhook'));

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $cred->refresh();
        $decrypted = $cred->getDecryptedCredentials();
        $this->assertSame('cwhsec_manualrotate123456789012345678901', $decrypted['webhook_signing_secret'] ?? null);
    }

    public function test_cajupay_webhook_pix_payment_paid_dispatches_process_payment_webhook(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $product = $this->createTestProduct(['price' => 50]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => $user->email,
            'gateway' => 'cajupay',
            'gateway_id' => 'pix-charge-001',
            'metadata' => ['cajupay_payment_id' => 'pix-charge-001'],
        ]);

        $cred = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
            'webhook_signing_secret' => 'cwhsec_testsecret123456789012345678901234',
        ]);
        $cred->save();

        $payload = [
            'type' => 'pix.payment.paid',
            'data' => [
                'object' => [
                    'payment_id' => 'pix-charge-001',
                    'cajupay_charge_id' => 'pix-charge-001',
                    'amount_cents' => 5000,
                    'currency' => 'brl',
                ],
            ],
        ];
        $raw = json_encode($payload);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, 'cwhsec_testsecret123456789012345678901234');

        $response = $this->postJson(route('webhooks.cajupay'), $payload, [
            'X-CajuPay-Event' => 'pix.payment.paid',
            'X-CajuPay-Timestamp' => $ts,
            'X-CajuPay-Signature' => 't='.$ts.',v1='.$sig,
        ]);

        $response->assertOk();
        $response->assertJsonPath('received', true);

        Bus::assertDispatchedSync(ProcessPaymentWebhook::class, function (ProcessPaymentWebhook $job) {
            return $job->gatewaySlug === 'cajupay'
                && $job->status === 'paid'
                && $job->event === 'order.paid';
        });
    }
}
