<?php

namespace Tests\Unit;

use App\Support\VapidEnvKeys;
use App\Support\VapidKeyGenerator;
use App\Support\VapidKeysManager;
use Tests\TestCase;

class VapidKeysManagerTest extends TestCase
{
    private string $testDir;

    private string $envPath;

    private string $sharedPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = storage_path('framework/testing/vapid-'.uniqid('', true));
        mkdir($this->testDir, 0777, true);
        mkdir($this->testDir.'/.docker', 0777, true);
        $this->envPath = $this->testDir.'/.env';
        $this->sharedPath = $this->testDir.'/.docker/pwa_vapid.env';
        file_put_contents($this->envPath, "APP_NAME=Test\n");
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $this->deleteDirectory($this->testDir);
        }

        parent::tearDown();
    }

    public function test_status_reports_not_configured_when_keys_missing(): void
    {
        $manager = new VapidKeysManager($this->envPath, $this->sharedPath);

        $status = $manager->status();

        $this->assertFalse($status['configured']);
        $this->assertNull($status['public_key']);
        $this->assertTrue($status['env_writable']);
        $this->assertTrue($status['env_exists']);
        $this->assertFalse($status['shared_file_exists']);
    }

    public function test_generate_writes_env_and_shared_file(): void
    {
        try {
            VapidKeyGenerator::createPair();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Geração VAPID indisponível neste ambiente: '.$e->getMessage());
        }

        $manager = new VapidKeysManager($this->envPath, $this->sharedPath);
        $result = $manager->generate();

        $this->assertTrue($result['success']);
        $this->assertTrue($result['configured']);
        $this->assertNotEmpty($result['public_key']);

        $envContent = (string) file_get_contents($this->envPath);
        $this->assertStringContainsString('PWA_VAPID_PUBLIC=', $envContent);
        $this->assertStringContainsString('PWA_VAPID_PRIVATE=', $envContent);
        $this->assertTrue(is_file($this->sharedPath));

        $status = $manager->status();
        $this->assertTrue($status['configured']);
        $this->assertSame($result['public_key'], $status['public_key']);
    }

    public function test_generate_without_force_is_idempotent_when_already_valid(): void
    {
        try {
            $keys = VapidKeyGenerator::createPair();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Geração VAPID indisponível neste ambiente: '.$e->getMessage());
        }

        file_put_contents(
            $this->envPath,
            "APP_NAME=Test\nPWA_VAPID_PUBLIC=\"{$keys['publicKey']}\"\nPWA_VAPID_PRIVATE=\"{$keys['privateKey']}\"\n"
        );

        $manager = new VapidKeysManager($this->envPath, $this->sharedPath);
        $result = $manager->generate(false);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['already_configured']);
        $this->assertTrue(VapidEnvKeys::normalizedPairLooksValid($keys['publicKey'], $keys['privateKey']));
    }

    public function test_ensure_configured_restores_from_shared_file_when_env_missing_keys(): void
    {
        try {
            $keys = VapidKeyGenerator::createPair();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Geração VAPID indisponível neste ambiente: '.$e->getMessage());
        }

        file_put_contents(
            $this->sharedPath,
            "PWA_VAPID_PUBLIC=\"{$keys['publicKey']}\"\nPWA_VAPID_PRIVATE=\"{$keys['privateKey']}\"\n"
        );

        $manager = new VapidKeysManager($this->envPath, $this->sharedPath);
        $result = $manager->ensureConfigured();

        $this->assertTrue($result['success']);
        $this->assertTrue($result['restored_from_shared'] ?? false);

        $envContent = (string) file_get_contents($this->envPath);
        $this->assertStringContainsString('PWA_VAPID_PUBLIC=', $envContent);
        $this->assertStringContainsString('PWA_VAPID_PRIVATE=', $envContent);

        $status = $manager->status();
        $this->assertTrue($status['configured']);
    }

    public function test_status_reads_valid_keys_from_shared_file_when_env_empty(): void
    {
        try {
            $keys = VapidKeyGenerator::createPair();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Geração VAPID indisponível neste ambiente: '.$e->getMessage());
        }

        file_put_contents(
            $this->sharedPath,
            "PWA_VAPID_PUBLIC=\"{$keys['publicKey']}\"\nPWA_VAPID_PRIVATE=\"{$keys['privateKey']}\"\n"
        );

        $manager = new VapidKeysManager($this->envPath, $this->sharedPath);
        $status = $manager->status();

        $this->assertTrue($status['configured']);
        $this->assertSame(VapidEnvKeys::normalize($keys['publicKey']), $status['public_key']);
    }

    public function test_read_env_value_uses_last_non_empty_when_duplicated(): void
    {
        try {
            $keys = VapidKeyGenerator::createPair();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Geração VAPID indisponível neste ambiente: '.$e->getMessage());
        }

        file_put_contents(
            $this->envPath,
            "APP_NAME=Test\nPWA_VAPID_PUBLIC=\nPWA_VAPID_PRIVATE=\nPWA_VAPID_PUBLIC=\"{$keys['publicKey']}\"\nPWA_VAPID_PRIVATE=\"{$keys['privateKey']}\"\n"
        );

        $manager = new VapidKeysManager($this->envPath, $this->sharedPath);

        $this->assertTrue($manager->status()['configured']);
        $pair = $manager->resolveKeyPair();
        $this->assertSame(VapidEnvKeys::normalize($keys['publicKey']), $pair['public']);
        $this->assertSame(VapidEnvKeys::normalize($keys['privateKey']), $pair['private']);
    }

    public function test_generate_fails_when_env_not_writable(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('chmod read-only não é confiável no Windows.');
        }

        chmod($this->envPath, 0444);

        $manager = new VapidKeysManager($this->envPath, $this->sharedPath);
        $result = $manager->generate();

        $this->assertFalse($result['success']);
        $this->assertSame('env_not_writable', $result['error']);

        chmod($this->envPath, 0644);
    }

    private function deleteDirectory(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
