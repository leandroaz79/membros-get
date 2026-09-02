<?php

namespace Tests\Unit;

use App\Models\GatewayCredential;
use App\Support\CheckoutPaymentMethodsBuilder;
use Tests\TestCase;

class CheckoutPaymentMethodsBuilderTest extends TestCase
{
    public function test_builder_skips_card_when_stripe_missing_publishable_key(): void
    {
        $cred = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'stripe',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['secret_key' => 'sk_only']);
        $cred->save();

        $methods = CheckoutPaymentMethodsBuilder::build(1, ['card' => 'stripe']);

        $this->assertSame([], $methods);
    }

    public function test_builder_includes_cajupay_wallets_with_keys(): void
    {
        $cred = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['public_key' => 'pk', 'secret_key' => 'sk']);
        $cred->save();

        $methods = CheckoutPaymentMethodsBuilder::build(1, [
            'card' => 'cajupay',
            'apple_pay' => 'cajupay',
            'google_pay' => 'cajupay',
        ]);

        $ids = CheckoutPaymentMethodsBuilder::methodIds($methods);
        $this->assertContains('card', $ids);
        $this->assertContains('apple_pay', $ids);
        $this->assertContains('google_pay', $ids);
    }
}
