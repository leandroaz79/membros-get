<?php

namespace Tests\Unit;

use App\Support\SharedHostingUpdater;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SharedHostingUpdaterTest extends TestCase
{
    public function test_should_preserve_env_storage_plugins_and_uploads(): void
    {
        $this->assertTrue(SharedHostingUpdater::shouldPreserveRelativePath('database/database.sqlite'));
        $this->assertTrue(SharedHostingUpdater::shouldPreserveRelativePath('database/tenant.sqlite'));
        $this->assertTrue(SharedHostingUpdater::shouldPreserveRelativePath('public/storage/products/photo.jpg'));
        $this->assertFalse(SharedHostingUpdater::shouldPreserveRelativePath('app/Http/Controllers/Foo.php'));
        $this->assertFalse(SharedHostingUpdater::shouldPreserveRelativePath('config/app.php'));
    }

    public function test_is_critical_relative_path(): void
    {
        $this->assertTrue(SharedHostingUpdater::isCriticalRelativePath('app/Models/User.php'));
        $this->assertTrue(SharedHostingUpdater::isCriticalRelativePath('public/build/assets/app.js'));
        $this->assertTrue(SharedHostingUpdater::isCriticalRelativePath('VERSION'));
        $this->assertFalse(SharedHostingUpdater::isCriticalRelativePath('README.md'));
    }

    public function test_copy_tree_skips_preserved_paths_and_copies_app_files(): void
    {
        $source = storage_path('framework/testing/update-source-' . uniqid());
        $target = storage_path('framework/testing/update-target-' . uniqid());

        File::makeDirectory($source . '/app/Http', 0755, true);
        File::makeDirectory($source . '/public/storage/uploads', 0755, true);
        File::put($source . '/app/Http/Controller.php', '<?php // new');
        File::put($source . '/public/storage/uploads/keep.jpg', 'keep-me');
        File::put($source . '/.env', 'SECRET=1');

        File::makeDirectory($target . '/public/storage/uploads', 0755, true);
        File::put($target . '/public/storage/uploads/keep.jpg', 'existing-upload');
        File::put($target . '/.env', 'SECRET=local');

        $result = SharedHostingUpdater::copyTree($source, $target);

        $this->assertSame('<?php // new', File::get($target . '/app/Http/Controller.php'));
        $this->assertSame('existing-upload', File::get($target . '/public/storage/uploads/keep.jpg'));
        $this->assertSame('SECRET=local', File::get($target . '/.env'));
        $this->assertGreaterThanOrEqual(1, $result['copied']);
        $this->assertSame([], $result['critical_errors']);

        File::deleteDirectory($source);
        File::deleteDirectory($target);
    }

    public function test_preflight_reports_writable_paths(): void
    {
        $preflight = SharedHostingUpdater::preflight();

        $this->assertArrayHasKey('ok', $preflight);
        $this->assertArrayHasKey('writable_paths', $preflight);
        $this->assertArrayHasKey('app/', $preflight['writable_paths']);
        $this->assertTrue($preflight['writable_paths']['app/']);
    }

    public function test_should_preserve_docker_volume_files(): void
    {
        $this->assertTrue(SharedHostingUpdater::shouldPreserveRelativePath('.docker/setup.done'));
        $this->assertTrue(SharedHostingUpdater::shouldPreserveRelativePath('.docker/plugins-installed/foo'));
    }

    public function test_update_mode_is_archive_inside_docker(): void
    {
        $previous = getenv('GETFY_DOCKER');
        putenv('GETFY_DOCKER=true');
        $_ENV['GETFY_DOCKER'] = 'true';
        $_SERVER['GETFY_DOCKER'] = 'true';

        try {
            $this->assertSame('archive', SharedHostingUpdater::updateMode());
        } finally {
            if ($previous === false) {
                putenv('GETFY_DOCKER');
                unset($_ENV['GETFY_DOCKER'], $_SERVER['GETFY_DOCKER']);
            } else {
                putenv('GETFY_DOCKER=' . $previous);
                $_ENV['GETFY_DOCKER'] = $previous;
                $_SERVER['GETFY_DOCKER'] = $previous;
            }
        }
    }
}
