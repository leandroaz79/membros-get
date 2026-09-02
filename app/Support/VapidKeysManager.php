<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Minishlink\WebPush\VAPID;

class VapidKeysManager
{
    public function __construct(
        private ?string $envPath = null,
        private ?string $sharedVapidPath = null,
    ) {}

    /**
     * @return array{
     *     configured: bool,
     *     public_key: string|null,
     *     env_writable: bool,
     *     env_exists: bool,
     *     shared_file_exists: bool
     * }
     */
    public function status(): array
    {
        $envPath = $this->resolveEnvPath();
        $envExists = is_file($envPath);
        $pair = $this->resolveKeyPair();
        $configured = $pair['public'] !== null && $pair['private'] !== null;

        return [
            'configured' => $configured,
            'public_key' => $pair['public'],
            'env_writable' => $envExists && is_writable($envPath),
            'env_exists' => $envExists,
            'shared_file_exists' => is_file($this->resolveSharedVapidPath()),
        ];
    }

    /**
     * Lê par VAPID válido do .env (última linha não vazia) ou do volume .docker/pwa_vapid.env.
     * Ignora config cache — necessário quando `php artisan config:cache` foi rodado sem as chaves.
     *
     * @return array{public: string|null, private: string|null}
     */
    public function resolveKeyPair(): array
    {
        foreach ([$this->resolveEnvPath(), $this->resolveSharedVapidPath()] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $content = (string) file_get_contents($path);
            $public = $this->readEnvValue($content, 'PWA_VAPID_PUBLIC');
            $private = $this->readEnvValue($content, 'PWA_VAPID_PRIVATE');
            if (VapidEnvKeys::normalizedPairLooksValid($public, $private)) {
                return [
                    'public' => VapidEnvKeys::normalize($public),
                    'private' => VapidEnvKeys::normalize($private),
                ];
            }
        }

        return ['public' => null, 'private' => null];
    }

    /**
     * Garante par VAPID válido: usa .env, restaura do volume Docker ou gera novo par.
     *
     * @return array{
     *     success: bool,
     *     configured?: bool,
     *     already_configured?: bool,
     *     restored_from_shared?: bool,
     *     public_key?: string|null,
     *     message: string,
     *     error?: string
     * }
     */
    public function ensureConfigured(bool $force = false): array
    {
        if ($force) {
            return $this->generate(true);
        }

        $status = $this->status();
        if ($status['configured'] && is_file($this->resolveEnvPath())) {
            $content = (string) file_get_contents($this->resolveEnvPath());
            $public = $this->readEnvValue($content, 'PWA_VAPID_PUBLIC');
            $private = $this->readEnvValue($content, 'PWA_VAPID_PRIVATE');
            if (VapidEnvKeys::normalizedPairLooksValid($public, $private)) {
                return [
                    'success' => true,
                    'already_configured' => true,
                    'configured' => true,
                    'public_key' => VapidEnvKeys::normalize($public),
                    'message' => 'Chaves VAPID já configuradas e válidas.',
                ];
            }
        }

        if ($this->restoreFromSharedEnvIfValid()) {
            $status = $this->status();

            return [
                'success' => true,
                'configured' => true,
                'restored_from_shared' => true,
                'public_key' => $status['public_key'],
                'message' => 'Chaves VAPID restauradas do arquivo compartilhado (.docker/pwa_vapid.env).',
            ];
        }

        return $this->generate(false);
    }

    /**
     * @return array{
     *     success: bool,
     *     configured?: bool,
     *     already_configured?: bool,
     *     public_key?: string|null,
     *     message: string,
     *     error?: string
     * }
     */
    public function generate(bool $force = false): array
    {
        $envPath = $this->resolveEnvPath();
        if (! is_file($envPath)) {
            return [
                'success' => false,
                'message' => 'Arquivo .env não encontrado.',
                'error' => 'env_missing',
            ];
        }

        if (! is_writable($envPath)) {
            return [
                'success' => false,
                'message' => 'Sem permissão para gravar o arquivo .env. Rode php artisan pwa:vapid no servidor.',
                'error' => 'env_not_writable',
            ];
        }

        $content = (string) file_get_contents($envPath);
        $existingPublic = $this->readEnvValue($content, 'PWA_VAPID_PUBLIC');
        $existingPrivate = $this->readEnvValue($content, 'PWA_VAPID_PRIVATE');

        if (! $force && VapidEnvKeys::normalizedPairLooksValid($existingPublic, $existingPrivate)) {
            return [
                'success' => true,
                'already_configured' => true,
                'configured' => true,
                'public_key' => VapidEnvKeys::normalize($existingPublic),
                'message' => 'Chaves VAPID já configuradas e válidas.',
            ];
        }

        try {
            $keys = VapidKeyGenerator::createPair();
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error' => 'generation_failed',
            ];
        }

        $publicKey = $keys['publicKey'];
        $privateKey = $keys['privateKey'];

        try {
            VAPID::validate([
                'subject' => 'mailto:validate@example.invalid',
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ]);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Chaves geradas falharam na validação: '.$e->getMessage(),
                'error' => 'validation_failed',
            ];
        }

        $written = $this->writeKeysToEnv($content, $publicKey, $privateKey);
        if (! $written) {
            return [
                'success' => false,
                'message' => 'Falha ao gravar chaves no .env.',
                'error' => 'write_failed',
            ];
        }

        $this->syncSharedVapidFile($publicKey, $privateKey);
        $this->refreshRuntimeConfig();

        return [
            'success' => true,
            'configured' => true,
            'public_key' => $publicKey,
            'message' => $force
                ? 'Chaves VAPID regeneradas e salvas no .env. Usuários com push ativo devem reativar notificações no painel.'
                : 'Chaves VAPID geradas e salvas automaticamente no .env.',
        ];
    }

    private function restoreFromSharedEnvIfValid(): bool
    {
        $sharedPath = $this->resolveSharedVapidPath();
        if (! is_file($sharedPath)) {
            return false;
        }

        $shared = (string) file_get_contents($sharedPath);
        $public = $this->readEnvValue($shared, 'PWA_VAPID_PUBLIC');
        $private = $this->readEnvValue($shared, 'PWA_VAPID_PRIVATE');
        if (! VapidEnvKeys::normalizedPairLooksValid($public, $private)) {
            return false;
        }

        $envPath = $this->resolveEnvPath();
        if (! is_file($envPath) || ! is_writable($envPath)) {
            return false;
        }

        $content = (string) file_get_contents($envPath);
        $written = $this->writeKeysToEnv(
            $content,
            VapidEnvKeys::normalize($public) ?? $public,
            VapidEnvKeys::normalize($private) ?? $private
        );
        if (! $written) {
            return false;
        }

        $this->refreshRuntimeConfig();

        return true;
    }

    private function writeKeysToEnv(string $content, string $publicKey, string $privateKey): bool
    {
        $hasPublic = (bool) preg_match('/^PWA_VAPID_PUBLIC=/m', $content);
        $hasPrivate = (bool) preg_match('/^PWA_VAPID_PRIVATE=/m', $content);

        $publicEscaped = '"'.str_replace('"', '\\"', $publicKey).'"';
        $privateEscaped = '"'.str_replace('"', '\\"', $privateKey).'"';

        if ($hasPublic) {
            $content = (string) preg_replace('/^PWA_VAPID_PUBLIC=.*/m', 'PWA_VAPID_PUBLIC='.$publicEscaped, $content);
        } else {
            $content .= "\n# PWA Painel: chaves VAPID (geradas via php artisan pwa:vapid)\n";
            $content .= 'PWA_VAPID_PUBLIC='.$publicEscaped."\n";
        }
        if ($hasPrivate) {
            $content = (string) preg_replace('/^PWA_VAPID_PRIVATE=.*/m', 'PWA_VAPID_PRIVATE='.$privateEscaped, $content);
        } else {
            $content .= 'PWA_VAPID_PRIVATE='.$privateEscaped."\n";
        }

        return file_put_contents($this->resolveEnvPath(), $content) !== false;
    }

    private function syncSharedVapidFile(string $publicKey, string $privateKey): void
    {
        $sharedPath = $this->resolveSharedVapidPath();
        $out = 'PWA_VAPID_PUBLIC="'.str_replace('"', '\\"', $publicKey)."\"\n";
        $out .= 'PWA_VAPID_PRIVATE="'.str_replace('"', '\\"', $privateKey)."\"\n";

        $dir = dirname($sharedPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        @file_put_contents($sharedPath, $out);
    }

    private function refreshRuntimeConfig(): void
    {
        try {
            Artisan::call('config:clear');
        } catch (\Throwable) {
            // ignore
        }

        if (DockerSetupState::isDocker()) {
            try {
                Artisan::call('queue:restart');
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    private function readEnvValue(string $content, string $key): ?string
    {
        if (! preg_match_all('/^\s*'.preg_quote($key, '/').'\s*=\s*(.*)\s*$/mi', $content, $matches)) {
            return null;
        }

        $last = null;
        foreach ($matches[1] as $raw) {
            $value = trim((string) $raw, " \t\n\r\0\x0B\"'`");
            if ($value !== '') {
                $last = $value;
            }
        }

        return $last;
    }

    private function resolveEnvPath(): string
    {
        return $this->envPath ?? base_path('.env');
    }

    private function resolveSharedVapidPath(): string
    {
        return $this->sharedVapidPath ?? base_path('.docker/pwa_vapid.env');
    }
}
