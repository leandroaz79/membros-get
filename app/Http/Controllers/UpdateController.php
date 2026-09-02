<?php

namespace App\Http\Controllers;

use App\Support\DockerSetupState;
use App\Support\GitPanelUpdater;
use App\Support\SharedHostingArtisan;
use App\Support\SharedHostingUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class UpdateController extends Controller
{
    private const GITHUB_RELEASES_LATEST = 'https://api.github.com/repos/getfy-opensource/getfy/releases/latest';
    private const GITHUB_TAGS = 'https://api.github.com/repos/getfy-opensource/getfy/tags';
    private const DOCKER_MANUAL_UPDATE_COMMAND = 'bash -c "$(curl -fsSL https://raw.githubusercontent.com/getfy-opensource/getfy/main/update.sh)"';

    private static function readInstalledVersion(): string
    {
        $versionFile = base_path('VERSION');
        $raw = trim((is_file($versionFile) ? (string) file_get_contents($versionFile) : '') ?: '');
        if ($raw !== '') {
            return $raw;
        }

        return (string) config('getfy.version');
    }

    /**
     * Ensure string is valid UTF-8 for JSON (avoids "Malformed UTF-8" on Windows console output).
     */
    private static function toUtf8(string $str): string
    {
        if ($str === '') {
            return $str;
        }
        $utf8 = @mb_convert_encoding($str, 'UTF-8', 'UTF-8');
        if ($utf8 !== false) {
            return $utf8;
        }
        if (function_exists('iconv')) {
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $str);
            if ($cleaned !== false) {
                return $cleaned;
            }
        }
        return preg_replace('/[^\x20-\x7E\x0A\x0D]/', '?', $str);
    }

    /**
     * Normalize version string (strip "v" prefix).
     */
    private static function normalizeVersion(string $tag): string
    {
        return ltrim(trim($tag), 'v');
    }

    /**
     * Get latest version from GitHub tags API (fallback when there are no releases).
     */
    private function getLatestFromTags(): ?string
    {
        $res = Http::timeout(10)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get(self::GITHUB_TAGS);

        if (! $res->successful()) {
            return null;
        }

        $tags = $res->json();
        if (! is_array($tags) || empty($tags)) {
            return null;
        }

        $latest = null;
        foreach ($tags as $tag) {
            $name = $tag['name'] ?? '';
            $ver = self::normalizeVersion((string) $name);
            if ($ver === '') {
                continue;
            }
            if (! preg_match('/^\d+\.\d+(\.\d+)?/', $ver)) {
                continue;
            }
            if ($latest === null || version_compare($ver, $latest, '>')) {
                $latest = $ver;
            }
        }

        return $latest;
    }

    private static function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    private static function ensureWritableDir(string $path): bool
    {
        try {
            if (! is_dir($path)) {
                File::makeDirectory($path, 0755, true);
            }
        } catch (\Throwable $e) {
            return false;
        }

        return is_dir($path) && is_writable($path);
    }

    private function resolveLatestTagRaw(): array
    {
        $res = Http::timeout(10)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get(self::GITHUB_RELEASES_LATEST);

        if ($res->successful()) {
            $data = $res->json();
            $tagName = (string) ($data['tag_name'] ?? '');
            $body = $data['body'] ?? null;
            return ['tag' => $tagName, 'changelog' => $body];
        }

        $tags = Http::timeout(10)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get(self::GITHUB_TAGS);
        if (! $tags->successful()) {
            return ['tag' => null, 'changelog' => null];
        }
        $list = $tags->json();
        if (! is_array($list) || empty($list)) {
            return ['tag' => null, 'changelog' => null];
        }

        $latestTagRaw = null;
        $latestNorm = null;
        foreach ($list as $tag) {
            $name = (string) ($tag['name'] ?? '');
            $norm = self::normalizeVersion($name);
            if ($norm === '' || ! preg_match('/^\d+\.\d+(\.\d+)?/', $norm)) {
                continue;
            }
            if ($latestNorm === null || version_compare($norm, $latestNorm, '>')) {
                $latestNorm = $norm;
                $latestTagRaw = $name;
            }
        }

        return ['tag' => $latestTagRaw, 'changelog' => null];
    }

    private static function withDockerManualHint(string $message): string
    {
        if (! DockerSetupState::isDocker()) {
            return $message;
        }

        return rtrim($message) . "\n\n" .
            "Docker: o painel atualiza baixando o pacote oficial (ZIP) dentro do container, preservando .env, storage/ e .docker/. "
            . "Para rebuild completo da imagem no servidor (opcional): " . self::DOCKER_MANUAL_UPDATE_COMMAND;
    }

    private static function cleanupViteHotFiles(): array
    {
        $paths = [
            public_path('hot'),
            storage_path('framework/vite.hot'),
        ];

        $deleted = [];
        $errors = [];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }
            try {
                if (File::delete($path)) {
                    $deleted[] = $path;
                } else {
                    $errors[] = $path;
                }
            } catch (\Throwable) {
                $errors[] = $path;
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    private function runArchiveUpdate(string $basePath, string $branch): array
    {
        @set_time_limit(600);
        @ini_set('max_execution_time', '600');

        $preflight = SharedHostingUpdater::preflight();
        if (! ($preflight['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'Servidor não está pronto para atualização automática: ' . implode(' ', $preflight['warnings'] ?? []),
                'details' => ['preflight' => $preflight],
            ];
        }

        SharedHostingUpdater::clearCachesBeforeUpdate();

        $tmpDir = storage_path('app' . DIRECTORY_SEPARATOR . '.update-tmp');
        if (! self::ensureWritableDir($tmpDir)) {
            $tmpDir = sys_get_temp_dir();
        }

        $meta = $this->resolveLatestTagRaw();
        $tagRaw = is_string($meta['tag'] ?? null) && ($meta['tag'] ?? '') !== '' ? (string) $meta['tag'] : null;
        $repo = 'getfy-opensource/getfy';
        $archiveUrl = $tagRaw
            ? 'https://github.com/' . $repo . '/archive/refs/tags/' . rawurlencode($tagRaw) . '.zip'
            : 'https://github.com/' . $repo . '/archive/refs/heads/' . rawurlencode($branch) . '.zip';

        $zipFile = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'getfy_update_' . Str::random(8) . '.zip';

        try {
            $res = Http::timeout(120)->withOptions(['sink' => $zipFile])->get($archiveUrl);
            if (! $res->successful() || ! is_file($zipFile) || filesize($zipFile) === 0) {
                @unlink($zipFile);
                return ['ok' => false, 'message' => 'Falha ao baixar o pacote de atualização.', 'details' => []];
            }
        } catch (\Throwable $e) {
            @unlink($zipFile);
            return ['ok' => false, 'message' => 'Erro ao baixar o pacote de atualização: ' . $e->getMessage(), 'details' => []];
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipFile) !== true) {
            @unlink($zipFile);
            return ['ok' => false, 'message' => 'Arquivo ZIP inválido.', 'details' => []];
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, '..')) {
                $zip->close();
                @unlink($zipFile);
                return ['ok' => false, 'message' => 'Arquivo ZIP inválido.', 'details' => []];
            }
            $entries[] = $name;
        }

        $extractTo = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'getfy_extract_' . Str::random(8);
        try {
            if (is_dir($extractTo)) {
                File::deleteDirectory($extractTo);
            }
            File::makeDirectory($extractTo, 0755, true);
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($zipFile);
            return ['ok' => false, 'message' => 'Erro ao preparar extração.', 'details' => []];
        }

        $zip->extractTo($extractTo);
        $zip->close();

        $baseInZip = null;
        foreach ($entries as $name) {
            $parts = explode('/', str_replace('\\', '/', trim((string) $name, '/')));
            $first = $parts[0] ?? '';
            if ($first === '' || $first === '.' || $first === '..') {
                continue;
            }
            if ($baseInZip === null) {
                $baseInZip = $first;
            } elseif ($baseInZip !== $first) {
                $baseInZip = '';
                break;
            }
        }

        $sourceDir = ($baseInZip && is_dir($extractTo . DIRECTORY_SEPARATOR . $baseInZip))
            ? $extractTo . DIRECTORY_SEPARATOR . $baseInZip
            : $extractTo;

        $result = SharedHostingUpdater::copyTree($sourceDir, $basePath);
        self::cleanupViteHotFiles();

        File::deleteDirectory($extractTo);
        @unlink($zipFile);

        $criticalErrors = $result['critical_errors'] ?? [];
        $minorErrors = array_values(array_diff($result['errors'] ?? [], $criticalErrors));
        $maxMinorErrors = 15;

        if ($criticalErrors !== []) {
            return [
                'ok' => false,
                'message' => 'Atualização interrompida: arquivos essenciais não puderam ser copiados. Verifique permissões da pasta (chmod 755) ou atualize via FTP.',
                'details' => [
                    ...$result,
                    'critical_errors' => $criticalErrors,
                    'minor_errors' => array_slice($minorErrors, 0, 50),
                ],
            ];
        }

        if (count($minorErrors) > $maxMinorErrors) {
            return [
                'ok' => false,
                'message' => 'Muitos arquivos secundários falharam ao copiar (' . count($minorErrors) . '). Permissões insuficientes na hospedagem.',
                'details' => [
                    ...$result,
                    'minor_errors' => array_slice($minorErrors, 0, 50),
                ],
            ];
        }

        $versionNorm = $tagRaw ? self::normalizeVersion($tagRaw) : null;
        if ($versionNorm) {
            @file_put_contents($basePath . DIRECTORY_SEPARATOR . 'VERSION', $versionNorm . "\n");
        }

        $composer = SharedHostingUpdater::tryComposerInstall($basePath);

        $message = 'Arquivos atualizados com sucesso.';
        if ($minorErrors !== []) {
            $message .= ' Alguns arquivos secundários foram ignorados (' . count($minorErrors) . ').';
        }

        return [
            'ok' => true,
            'message' => $message,
            'details' => [
                ...$result,
                'minor_errors' => array_slice($minorErrors, 0, 20),
                'composer' => $composer,
            ],
            'tag' => $versionNorm,
            'changelog' => $meta['changelog'] ?? null,
        ];
    }

    /**
     * Check for updates (GitHub Releases API, fallback to Tags API).
     */
    public function check(): JsonResponse
    {
        $current = self::readInstalledVersion();
        $preflight = SharedHostingUpdater::preflight();
        $response = [
            'current' => $current,
            'latest' => null,
            'available' => false,
            'error' => null,
            'changelog_remote' => null,
            'update_mode' => SharedHostingUpdater::updateMode(),
            'docker_mode' => DockerSetupState::isDocker(),
            'archive_ready' => (bool) ($preflight['ok'] ?? false),
            'preflight_warnings' => $preflight['warnings'] ?? [],
        ];

        try {
            $res = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get(self::GITHUB_RELEASES_LATEST);

            if ($res->successful()) {
                $data = $res->json();
                $tagName = $data['tag_name'] ?? '';
                $latest = self::normalizeVersion((string) $tagName);
                $response['latest'] = $latest;
                $response['changelog_remote'] = $data['body'] ?? null;

                if ($latest !== '' && version_compare($latest, $current, '>')) {
                    $response['available'] = true;
                }

                return response()->json($response);
            }

            if ($res->status() === 404) {
                $latestFromTags = $this->getLatestFromTags();
                if ($latestFromTags !== null) {
                    $response['latest'] = $latestFromTags;
                    if (version_compare($latestFromTags, $current, '>')) {
                        $response['available'] = true;
                    }
                    $response['error'] = null;

                    return response()->json($response);
                }
                $response['error'] = 'Nenhuma release nem tag de versão encontrada. Crie uma Release ou uma tag (ex: v1.0.0) no GitHub.';

                return response()->json($response);
            }

            $response['error'] = 'Não foi possível verificar atualizações. Tente novamente mais tarde.';

            return response()->json($response);
        } catch (\Throwable $e) {
            $response['error'] = 'Erro ao verificar: ' . $e->getMessage();
        }

        return response()->json($response);
    }

    public function integrity(): JsonResponse
    {
        $response = [
            'repository_exists' => null,
            'total_migrations' => 0,
            'ran_count' => 0,
            'pending_count' => 0,
            'pending' => [],
            'pending_truncated' => false,
            'error' => null,
        ];

        try {
            $migrator = app('migrator');
            if (! $migrator || ! method_exists($migrator, 'getMigrationFiles') || ! method_exists($migrator, 'getRepository')) {
                return response()->json([
                    ...$response,
                    'error' => 'Migrator indisponível.',
                ], 500);
            }

            $paths = [];
            $defaultPath = database_path('migrations');
            if (is_dir($defaultPath)) {
                $paths[] = $defaultPath;
            }
            if (method_exists($migrator, 'paths')) {
                $customPaths = $migrator->paths();
                if (is_array($customPaths)) {
                    $paths = array_merge($paths, $customPaths);
                }
            }
            $paths = array_values(array_unique(array_filter($paths, fn ($p) => is_string($p) && $p !== '')));

            $files = $migrator->getMigrationFiles($paths);
            if (! is_array($files)) {
                $files = [];
            }

            $repositoryExists = method_exists($migrator, 'repositoryExists') ? (bool) $migrator->repositoryExists() : null;
            $response['repository_exists'] = $repositoryExists;
            $response['total_migrations'] = count($files);

            $ran = [];
            $repo = $migrator->getRepository();
            if ($repositoryExists && $repo && method_exists($repo, 'getRan')) {
                $ran = $repo->getRan();
                if (! is_array($ran)) {
                    $ran = [];
                }
            }
            $response['ran_count'] = count($ran);

            $pending = [];
            foreach ($files as $name => $path) {
                if (! is_string($name) || $name === '') {
                    continue;
                }
                if (in_array($name, $ran, true)) {
                    continue;
                }
                $pending[] = $name;
            }

            $response['pending_count'] = count($pending);
            $maxList = 50;
            $response['pending'] = array_slice($pending, 0, $maxList);
            $response['pending_truncated'] = count($pending) > $maxList;
        } catch (\Throwable $e) {
            $response['error'] = 'Erro ao verificar integridade: ' . self::toUtf8($e->getMessage());
        }

        return response()->json($response);
    }

    public function migrateNow(Request $request): JsonResponse|RedirectResponse
    {
        try {
            SharedHostingArtisan::clearCaches(base_path());
            $result = SharedHostingArtisan::runMigrateAll(base_path(), 90, SharedHostingArtisan::DEFAULT_MIGRATE_CHUNK);
            $output = self::toUtf8((string) ($result['output'] ?? ''));

            if (! ($result['ok'] ?? false)) {
                $msg = self::withDockerManualHint((string) ($result['error'] ?? 'Falha ao rodar migrations.'));
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $msg,
                        'output' => $output,
                        'pending' => (int) ($result['pending'] ?? 0),
                        'partial' => (bool) ($result['partial'] ?? false),
                    ], 422);
                }

                return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
            }

            try {
                \Illuminate\Support\Facades\Artisan::call('config:clear');
                \Illuminate\Support\Facades\Artisan::call('config:cache');
            } catch (\Throwable) {
            }

            $ran = (int) ($result['ran'] ?? 0);
            $msg = ($result['partial'] ?? false)
                ? "Migrations parcialmente executadas ({$ran} nesta rodada). Clique novamente para continuar."
                : ($ran > 0 ? "Migrations executadas com sucesso ({$ran} nesta rodada)." : 'Migrations executadas com sucesso.');

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'output' => $output,
                    'pending' => (int) ($result['pending'] ?? 0),
                    'partial' => (bool) ($result['partial'] ?? false),
                ]);
            }

            return redirect()->route('settings.index', ['tab' => 'update'])->with('success', $msg);
        } catch (\Throwable $e) {
            $msg = self::withDockerManualHint('Falha ao rodar migrations: ' . self::toUtf8($e->getMessage()));
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }

            return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
        }
    }

    /**
     * Run update: git pull, composer, npm build, migrate.
     */
    public function run(Request $request): JsonResponse|RedirectResponse
    {
        @set_time_limit(600);
        @ini_set('max_execution_time', '600');

        if (! config('getfy.updates_enabled', true)) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Atualizações pela interface estão desativadas.'], 403);
            }

            return redirect()->route('settings.index', ['tab' => 'update'])
                ->with('error', 'Atualizações pela interface estão desativadas.');
        }

        $action = $request->input('action');
        if ($request->wantsJson() && is_string($action) && $action !== '') {
            if ($action === 'integrity') {
                return $this->integrity();
            }
            if ($action === 'migrate') {
                return $this->migrateNow($request);
            }
        }

        $basePath = base_path();
        $branch = config('getfy.update_branch', 'main');
        $expectedRepo = config('getfy.update_repository_url', 'https://github.com/getfy-opensource/getfy.git');
        $timeout = 300;

        // PHP executável (servidor web muitas vezes não tem PHP no PATH; usar caminho explícito ou GETFY_PHP_PATH)
        $phpBinary = null;
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $phpBinary = PHP_BINARY;
        } elseif (config('getfy.php_path')) {
            $phpPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, config('getfy.php_path')), DIRECTORY_SEPARATOR);
            $phpBinary = $phpPath . DIRECTORY_SEPARATOR . 'php.exe';
            if (! is_file($phpBinary)) {
                $phpBinary = $phpPath . DIRECTORY_SEPARATOR . 'php';
            }
        }
        $pathEnv = getenv('PATH') ?: '';
        if ($phpBinary !== null && $phpBinary !== '') {
            $phpDir = dirname($phpBinary);
            $pathEnv = $phpDir . PATH_SEPARATOR . $pathEnv;
        }
        $processEnv = ['PATH' => $pathEnv];
        $homeDir = getenv('HOME');
        if (! is_string($homeDir) || trim($homeDir) === '' || ! self::ensureWritableDir($homeDir)) {
            $homeDir = storage_path('app' . DIRECTORY_SEPARATOR . '.composer-home');
        }
        if (self::ensureWritableDir($homeDir)) {
            $processEnv['HOME'] = $homeDir;
            $processEnv['COMPOSER_HOME'] = $homeDir;
            $processEnv['COMPOSER_CACHE_DIR'] = $homeDir . DIRECTORY_SEPARATOR . 'cache';
            $processEnv['COMPOSER_ALLOW_SUPERUSER'] = '1';
        }

        $steps = [];
        $runStep = function (string $command, string $label) use ($basePath, $timeout, $processEnv, &$steps): bool {
            $result = Process::path($basePath)->timeout($timeout)->env($processEnv)->run($command);
            $steps[] = [
                'label' => $label,
                'ok' => $result->successful(),
                'output' => self::toUtf8($result->output()),
                'error' => self::toUtf8($result->errorOutput()),
            ];
            if (! $result->successful()) {
                return false;
            }
            return true;
        };

        $hasGitRepo = is_dir($basePath . DIRECTORY_SEPARATOR . '.git');
        $useGitUpdate = $hasGitRepo
            && SharedHostingUpdater::canRunProcess()
            && SharedHostingUpdater::updateMode() === 'git';

        if ($useGitUpdate) {
            $gitResult = GitPanelUpdater::run($basePath, $branch, $runStep);
            if (! ($gitResult['ok'] ?? false)) {
                $last = end($steps);
                $detail = is_array($last) ? self::toUtf8($last['error'] ?: $last['output'] ?: '') : '';
                $msg = self::withDockerManualHint(
                    ($gitResult['message'] ?? 'Falha ao atualizar código.')
                    . ($detail !== '' ? ' ' . $detail : '')
                );
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg, 'steps' => $steps], 422);
                }

                return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
            }

            $composerCmd = 'composer install --no-interaction --no-dev';
            $vendorComposer = $basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'composer';
            if ($phpBinary !== null && $phpBinary !== '' && is_file($phpBinary) && is_file($vendorComposer)) {
                $vendorComposerRelative = 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'composer';
                $composerCmd = '"' . $phpBinary . '" ' . $vendorComposerRelative . ' install --no-interaction --no-dev';
            }
            if (! $runStep($composerCmd, 'Composer install')) {
                $last = end($steps);
                $msg = self::withDockerManualHint('Falha no Composer: ' . self::toUtf8($last['error'] ?: $last['output'] ?: 'erro desconhecido'));
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg, 'steps' => $steps], 422);
                }

                return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
            }

            $skipNpm = DockerSetupState::isDocker();
            if ($skipNpm) {
                $steps[] = [
                    'label' => 'NPM build',
                    'ok' => true,
                    'output' => 'Docker: build do frontend já incluído no pacote de release.',
                    'error' => '',
                ];
            } else {
                $npmVersion = Process::path($basePath)->timeout(10)->env($processEnv)->run('npm --version');
                if (! $npmVersion->successful()) {
                    $steps[] = [
                        'label' => 'NPM build',
                        'ok' => true,
                        'output' => 'npm não encontrado; pulando build.',
                        'error' => self::toUtf8($npmVersion->errorOutput()),
                    ];
                } elseif (! $runStep('npm ci && npm run build', 'NPM build')) {
                    $last = end($steps);
                    $msg = self::withDockerManualHint('Falha no build do frontend: ' . self::toUtf8($last['error'] ?: $last['output'] ?: 'erro desconhecido'));
                    if ($request->wantsJson()) {
                        return response()->json(['success' => false, 'message' => $msg, 'steps' => $steps], 422);
                    }

                    return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
                }
            }

            $cleanup = self::cleanupViteHotFiles();
            $steps[] = [
                'label' => 'Limpeza Vite hot',
                'ok' => empty($cleanup['errors']),
                'output' => ! empty($cleanup['deleted']) ? implode("\n", $cleanup['deleted']) : '',
                'error' => ! empty($cleanup['errors']) ? implode("\n", $cleanup['errors']) : '',
            ];
        } else {
            $archive = $this->runArchiveUpdate($basePath, $branch);
            $archiveDetails = is_array($archive['details'] ?? null) ? $archive['details'] : [];
            $errorLines = array_merge(
                $archiveDetails['critical_errors'] ?? [],
                $archiveDetails['minor_errors'] ?? array_slice($archiveDetails['errors'] ?? [], 0, 30)
            );
            $steps[] = [
                'label' => 'Atualização por download (ZIP)',
                'ok' => (bool) ($archive['ok'] ?? false),
                'output' => self::toUtf8(
                    (string) ($archive['message'] ?? '')
                    . (isset($archiveDetails['copied']) ? "\nCopiados: {$archiveDetails['copied']}, ignorados: {$archiveDetails['skipped']}" : '')
                ),
                'error' => self::toUtf8(implode("\n", array_map(
                    fn ($p) => is_string($p) ? $p : (string) $p,
                    $errorLines
                ))),
            ];

            $composerResult = $archiveDetails['composer'] ?? null;
            if (is_array($composerResult)) {
                $steps[] = [
                    'label' => 'Composer (dependências PHP)',
                    'ok' => (bool) ($composerResult['ok'] ?? true),
                    'output' => self::toUtf8((string) ($composerResult['output'] ?? '')),
                    'error' => self::toUtf8((string) ($composerResult['error'] ?? '')),
                ];
            }

            if (! ($archive['ok'] ?? false)) {
                $msg = self::withDockerManualHint((string) ($archive['message'] ?? 'Falha ao atualizar.'));
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg, 'steps' => $steps], 422);
                }
                return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
            }

            $cleanup = self::cleanupViteHotFiles();
            $steps[] = [
                'label' => 'Limpeza Vite hot',
                'ok' => empty($cleanup['errors']),
                'output' => ! empty($cleanup['deleted']) ? implode("\n", $cleanup['deleted']) : '',
                'error' => ! empty($cleanup['errors']) ? implode("\n", $cleanup['errors']) : '',
            ];
        }

        // 4. Migrate
        try {
            SharedHostingArtisan::clearCaches($basePath);
            $migrateResult = SharedHostingArtisan::runMigrateAll($basePath, 120, SharedHostingArtisan::DEFAULT_MIGRATE_CHUNK);
            $steps[] = [
                'label' => 'Migrations',
                'ok' => (bool) ($migrateResult['ok'] ?? false) && ! ($migrateResult['partial'] ?? false),
                'output' => self::toUtf8((string) ($migrateResult['output'] ?? '')),
                'error' => ($migrateResult['partial'] ?? false)
                    ? self::toUtf8('Restam ' . (int) ($migrateResult['pending'] ?? 0) . ' migrations — rode novamente em Configurações > Update.')
                    : self::toUtf8((string) ($migrateResult['error'] ?? '')),
            ];
            if (! ($migrateResult['ok'] ?? false) || ($migrateResult['partial'] ?? false)) {
                $msg = self::withDockerManualHint(
                    ($migrateResult['partial'] ?? false)
                        ? 'Atualização de arquivos OK, mas migrations incompletas. Vá em Configurações > Update > Rodar migrations.'
                        : ((string) ($migrateResult['error'] ?? 'Falha nas migrations.'))
                );
                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'message' => $msg, 'steps' => $steps], 422);
                }

                return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
            }
        } catch (\Throwable $e) {
            $msg = self::withDockerManualHint('Falha nas migrations: ' . self::toUtf8($e->getMessage()));
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg, 'steps' => $steps], 422);
            }

            return redirect()->route('settings.index', ['tab' => 'update'])->with('error', $msg);
        }

        // 5. Config cache
        try {
            \Illuminate\Support\Facades\Artisan::call('config:clear');
            \Illuminate\Support\Facades\Artisan::call('config:cache');
        } catch (\Throwable $e) {
            // Non-fatal
        }

        if (DockerSetupState::isDocker()) {
            try {
                \Illuminate\Support\Facades\Artisan::call('queue:restart');
            } catch (\Throwable) {
            }
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Atualização concluída com sucesso.', 'redirect' => route('settings.index', ['tab' => 'update']), 'steps' => $steps]);
        }

        return redirect()->route('settings.index', ['tab' => 'update'])->with('success', 'Atualização concluída com sucesso.');
    }
}
