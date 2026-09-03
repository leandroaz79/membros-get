<?php

namespace Tests\Unit;

use App\Support\VapidEnvKeys;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

class VapidEnvKeysTest extends TestCase
{
    public function test_normalized_pair_rejects_empty_values(): void
    {
        $this->assertFalse(VapidEnvKeys::normalizedPairLooksValid(null, null));
        $this->assertFalse(VapidEnvKeys::normalizedPairLooksValid('', ''));
    }

    public function test_normalized_pair_rejects_invalid_lengths(): void
    {
        $this->assertFalse(VapidEnvKeys::normalizedPairLooksValid('not-a-key', 'also-not-a-key'));
    }

    public function test_normalized_pair_accepts_generated_keys(): void
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $this->markTestSkipped('OpenSSL EC indisponível neste ambiente: '.$e->getMessage());
        }

        $this->assertTrue(
            VapidEnvKeys::normalizedPairLooksValid($keys['publicKey'], $keys['privateKey'])
        );
    }

    public function test_normalize_strips_whitespace_and_standard_base64(): void
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $this->markTestSkipped('OpenSSL EC indisponível neste ambiente: '.$e->getMessage());
        }

        $publicWithSpaces = "  \n".$keys['publicKey']."\n  ";

        $this->assertTrue(
            VapidEnvKeys::normalizedPairLooksValid($publicWithSpaces, $keys['privateKey'])
        );
    }
}
