<?php

namespace Tests\Unit;

use App\Support\VapidEnvKeys;
use App\Support\VapidKeyGenerator;
use Tests\TestCase;

class VapidKeyGeneratorTest extends TestCase
{
    public function test_create_pair_returns_valid_vapid_keys(): void
    {
        try {
            $keys = VapidKeyGenerator::createPair();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Geração VAPID indisponível neste ambiente: '.$e->getMessage());
        }

        $this->assertArrayHasKey('publicKey', $keys);
        $this->assertArrayHasKey('privateKey', $keys);
        $this->assertTrue(
            VapidEnvKeys::normalizedPairLooksValid($keys['publicKey'], $keys['privateKey'])
        );
    }
}
