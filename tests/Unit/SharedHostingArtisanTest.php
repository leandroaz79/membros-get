<?php

namespace Tests\Unit;

use App\Support\SharedHostingArtisan;
use Exception;
use Illuminate\Foundation\Application;
use Tests\TestCase;

class SharedHostingArtisanTest extends TestCase
{
    public function test_humanize_migration_error_for_duplicate_table(): void
    {
        $msg = SharedHostingArtisan::humanizeMigrationError(
            new Exception('SQLSTATE[42S01]: Base table or view already exists')
        );

        $this->assertStringContainsString('já existe', $msg);
        $this->assertStringContainsString('migrations', $msg);
    }

    public function test_humanize_migration_error_for_access_denied(): void
    {
        $msg = SharedHostingArtisan::humanizeMigrationError(
            new Exception('SQLSTATE[HY000] [1045] Access denied for user')
        );

        $this->assertStringContainsString('Acesso negado', $msg);
    }

    public function test_humanize_migration_error_for_missing_vendor(): void
    {
        $msg = SharedHostingArtisan::humanizeMigrationError(
            new Exception('vendor/autoload.php não encontrado')
        );

        $this->assertStringContainsString('vendor', $msg);
    }

    public function test_bootstrap_returns_application_not_console_kernel(): void
    {
        $app = SharedHostingArtisan::bootstrap(base_path());

        $this->assertInstanceOf(Application::class, $app);
    }

    public function test_run_migrate_chunk_bootstraps_without_get_application_fatal(): void
    {
        $result = SharedHostingArtisan::runMigrateChunk(base_path(), 1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('done', $result);
        $this->assertArrayHasKey('ran', $result);
        $this->assertGreaterThanOrEqual(0, $result['ran']);

        $error = strtolower((string) ($result['error'] ?? ''));
        $this->assertStringNotContainsString('getapplication', $error);
        $this->assertStringNotContainsString('undefined method', $error);

        $this->assertTrue($result['ok'] ?? false, $result['error'] ?? 'runMigrateChunk failed');
    }
}
