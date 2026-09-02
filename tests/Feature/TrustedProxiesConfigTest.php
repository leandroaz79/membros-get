<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use ReflectionClass;
use Tests\TestCase;

class TrustedProxiesConfigTest extends TestCase
{
    public function test_bootstrap_does_not_trust_all_proxies_by_default(): void
    {
        $ref = new ReflectionClass(TrustProxies::class);
        $prop = $ref->getProperty('alwaysTrustProxies');
        $prop->setAccessible(true);
        $trusted = $prop->getValue();

        // TRUSTED_PROXIES vazio no phpunit → lista vazia (nunca '*').
        $this->assertNotSame('*', $trusted);
        $this->assertTrue($trusted === [] || $trusted === null || $trusted === '');
    }
}
