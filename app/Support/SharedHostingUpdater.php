<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Atualização por download (ZIP do GitHub) — hospedagem compartilhada sem Git/SSH.
 */
class SharedHostingUpdater
{
    /** Pastas do projeto que nunca são sobrescritas pelo ZIP. */
    public const PRESERVE_TOP_LEVEL = ['.env', '.git', '.install', '.docker', 'storage', 'plugins', 'node_modules'];

    /** Prefixos considerados críticos — falha aqui impede concluir a atualização. */
    private const CRITICAL_PREFIXES = [
        'app/',
        'config/',
        'routes/',
        'public/build/',
        'bootstrap/app.php',
        'resources/views/',
    ];

    public static function updateMode(): string
    {
        if (DockerSetupState::isDocker()) {
            return 'archive';
        }

        $hasGit = is_dir(base_path('.git'));
        if ($hasGit && self::canRunProcess()) {
            return 'git';
        }

        return 'archive';
    }

    public static function canRunProcess(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }
        $disabled = ini_get('disable_functions');
        if (! is_string($disabled) || trim($disabled) === '') {
            return true;
        }

        return ! in_array('proc_open', array_map('trim', explode(',', $disabled)), true);
    }

    /**
     * @return array{ok: bool, warnings: array<int, string>, zip_available: bool, writable: bool, writable_paths: array<string, bool>}
     */
    public static function preflight(): array
    {
        $warnings = [];
        $zipAvailable = class_exists('ZipArchive');
        if (! $zipAvailable) {
            $warnings[] = 'Extensão PHP Zip (ZipArchive) não está habilitada.';
        }

        $writablePaths = self::checkWritablePaths();
        $writable = ! in_array(false, $writablePaths, true);
        if (! $writable) {
            $blocked = array_keys(array_filter($writablePaths, fn (bool $ok) => ! $ok));
            $warnings[] = 'Sem permissão de escrita em: ' . implode(', ', $blocked) . '. Ajuste chmod (755 nas pastas, 644 nos arquivos) ou peça ao suporte da hospedagem.';
        }

        if (! is_writable(storage_path('app'))) {
            @mkdir(storage_path('app'), 0755, true);
        }

        return [
            'ok' => $zipAvailable && $writable,
            'warnings' => $warnings,
            'zip_available' => $zipAvailable,
            'writable' => $writable,
            'writable_paths' => $writablePaths,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function checkWritablePaths(): array
    {
        $paths = [
            'raiz' => base_path(),
            'app/' => base_path('app'),
            'config/' => base_path('config'),
            'routes/' => base_path('routes'),
            'public/' => public_path(),
            'public/build/' => public_path('build'),
            'bootstrap/cache/' => base_path('bootstrap/cache'),
            'resources/views/' => resource_path('views'),
        ];

        $result = [];
        foreach ($paths as $label => $path) {
            if (! is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            $result[$label] = is_dir($path) && is_writable($path);
        }

        return $result;
    }

    public static function clearCachesBeforeUpdate(): void
    {
        $cacheDir = base_path('bootstrap/cache');
        if (! is_dir($cacheDir)) {
            return;
        }
        foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        foreach ([public_path('hot'), storage_path('framework/vite.hot')] as $hot) {
            if (is_file($hot)) {
                @unlink($hot);
            }
        }
    }

    /**
     * @return array{copied: int, skipped: int, errors: array<int, string>, critical_errors: array<int, string>}
     */
    public static function copyTree(string $sourceDir, string $targetDir): array
    {
        $sourceDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourceDir), DIRECTORY_SEPARATOR);
        $targetDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetDir), DIRECTORY_SEPARATOR);

        $copied = 0;
        $skipped = 0;
        $errors = [];

        $files = File::allFiles($sourceDir);
        foreach ($files as $file) {
            $path = $file->getPathname();
            $relative = ltrim(str_replace($sourceDir, '', $path), DIRECTORY_SEPARATOR);
            $relativeNormalized = str_replace(['\\', '/'], '/', $relative);

            if ($relativeNormalized === '' || str_contains($relativeNormalized, '..')) {
                $skipped++;
                continue;
            }

            if (self::shouldPreserveRelativePath($relativeNormalized)) {
                $skipped++;
                continue;
            }

            $parts = explode('/', $relativeNormalized);
            $top = $parts[0] ?? '';
            if ($top !== '' && in_array($top, self::PRESERVE_TOP_LEVEL, true)) {
                $skipped++;
                continue;
            }

            $targetPath = $targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeNormalized);
            $targetParent = dirname($targetPath);

            if (! is_dir($targetParent)) {
                try {
                    self::ensureDirectory($targetParent);
                } catch (Throwable) {
                    $errors[] = $relativeNormalized;
                    continue;
                }
            } elseif (! is_writable($targetParent)) {
                self::ensureDirectory($targetParent);
            }

            if (is_file($targetPath) && self::filesAreIdentical($path, $targetPath)) {
                $skipped++;
                continue;
            }

            if (self::writeFile($path, $targetPath)) {
                $copied++;
                continue;
            }

            $errors[] = $relativeNormalized;
        }

        return [
            'copied' => $copied,
            'skipped' => $skipped,
            'errors' => $errors,
            'critical_errors' => self::filterCriticalErrors($errors),
        ];
    }

    public static function shouldPreserveRelativePath(string $relativeNormalized): bool
    {
        if ($relativeNormalized === 'database/database.sqlite') {
            return true;
        }
        if (preg_match('#^database/.+\.sqlite$#i', $relativeNormalized)) {
            return true;
        }
        if (str_starts_with($relativeNormalized, 'public/storage/')) {
            return true;
        }
        if (str_starts_with($relativeNormalized, '.docker/')) {
            return true;
        }

        return false;
    }

    public static function filterCriticalErrors(array $errors): array
    {
        return array_values(array_filter($errors, fn (string $path) => self::isCriticalRelativePath($path)));
    }

    public static function isCriticalRelativePath(string $relativeNormalized): bool
    {
        foreach (self::CRITICAL_PREFIXES as $prefix) {
            if (str_starts_with($relativeNormalized, $prefix)) {
                return true;
            }
        }

        return in_array($relativeNormalized, ['VERSION', 'artisan', 'composer.json', 'composer.lock'], true);
    }

    /**
     * @return array{ok: bool, output: string, error: string}
     */
    public static function tryComposerInstall(string $basePath): array
    {
        $basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $vendorComposer = $basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'composer';
        $composerPhar = $basePath . DIRECTORY_SEPARATOR . 'composer.phar';

        if (self::canRunProcess() && is_file($vendorComposer)) {
            $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg($vendorComposer) . ' install --no-interaction --no-dev --optimize-autoloader 2>&1';
            $output = [];
            $code = 1;
            @exec($cmd, $output, $code);
            $text = implode("\n", $output);

            return [
                'ok' => $code === 0,
                'output' => $text,
                'error' => $code === 0 ? '' : $text,
            ];
        }

        if (is_file($composerPhar)) {
            try {
                $cwd = getcwd();
                chdir($basePath);
                putenv('COMPOSER_HOME=' . $basePath . '/.composer');
                $pharUri = 'phar://' . $composerPhar;
                $autoload = is_file($pharUri . '/vendor/autoload.php')
                    ? $pharUri . '/vendor/autoload.php'
                    : $pharUri . '/autoload.php';
                if (! is_file($autoload)) {
                    chdir($cwd ?: $basePath);

                    return ['ok' => false, 'output' => '', 'error' => 'composer.phar inválido.'];
                }
                require $autoload;
                $app = new \Composer\Console\Application();
                $app->setAutoExit(false);
                $input = new \Symfony\Component\Console\Input\ArrayInput([
                    'command' => 'install',
                    '--no-interaction' => true,
                    '--no-dev' => true,
                    '--optimize-autoloader' => true,
                ]);
                $stream = fopen('php://temp', 'w+');
                $outputObj = new \Symfony\Component\Console\Output\StreamOutput($stream);
                $exitCode = $app->run($input, $outputObj);
                rewind($stream);
                $text = (string) stream_get_contents($stream);
                fclose($stream);
                chdir($cwd ?: $basePath);

                return [
                    'ok' => $exitCode === 0,
                    'output' => $text,
                    'error' => $exitCode === 0 ? '' : $text,
                ];
            } catch (Throwable $e) {
                return ['ok' => false, 'output' => '', 'error' => $e->getMessage()];
            }
        }

        return [
            'ok' => true,
            'output' => 'Composer não disponível neste servidor; dependências PHP existentes mantidas.',
            'error' => '',
        ];
    }

    private static function filesAreIdentical(string $source, string $target): bool
    {
        if (! is_file($target)) {
            return false;
        }
        $srcSize = @filesize($source);
        $tgtSize = @filesize($target);
        if ($srcSize === false || $tgtSize === false || $srcSize !== $tgtSize) {
            return false;
        }
        if ($srcSize > 5_000_000) {
            return false;
        }

        return @md5_file($source) === @md5_file($target);
    }

    private static function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            File::makeDirectory($path, 0755, true);
        }
        if (! is_writable($path)) {
            @chmod($path, 0755);
        }
    }

    private static function writeFile(string $source, string $target): bool
    {
        if (is_file($target) && ! is_writable($target)) {
            @chmod($target, 0644);
        }

        if (@copy($source, $target)) {
            @chmod($target, 0644);

            return true;
        }

        $contents = @file_get_contents($source);
        if ($contents === false) {
            return false;
        }

        $tempTarget = $target . '.getfy-' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tempTarget, $contents) !== false) {
            if (@rename($tempTarget, $target)) {
                @chmod($target, 0644);

                return true;
            }
            if (@copy($tempTarget, $target)) {
                @unlink($tempTarget);
                @chmod($target, 0644);

                return true;
            }
            @unlink($tempTarget);
        }

        if (@file_put_contents($target, $contents) !== false) {
            @chmod($target, 0644);

            return true;
        }

        return false;
    }
}
