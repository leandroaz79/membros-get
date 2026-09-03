<?php

namespace App\Support;

/**
 * Validação de checksum / assinatura de pacotes ZIP de plugins.
 */
final class PluginPackageIntegrity
{
    /**
     * @return array<int, string> erros (vazio = ok)
     */
    public static function verifyExtractedPackage(string $pluginPath): array
    {
        $errors = [];
        $checksumFile = $pluginPath.DIRECTORY_SEPARATOR.'.getfy-checksum';
        if (is_file($checksumFile)) {
            $expected = strtolower(trim((string) file_get_contents($checksumFile)));
            if ($expected !== '' && preg_match('/^[a-f0-9]{64}$/', $expected)) {
                $actual = self::hashDirectory($pluginPath, ['.getfy-checksum', '.getfy-signature']);
                if (! hash_equals($expected, $actual)) {
                    $errors[] = 'Checksum SHA-256 do pacote não confere (.getfy-checksum).';
                }
            }
        }

        $sigFile = $pluginPath.DIRECTORY_SEPARATOR.'.getfy-signature';
        $publicKey = trim((string) config('plugins.package_public_key', ''));
        if (is_file($sigFile) && $publicKey !== '') {
            $payload = self::hashDirectory($pluginPath, ['.getfy-checksum', '.getfy-signature']);
            $signature = base64_decode((string) file_get_contents($sigFile), true);
            if ($signature === false) {
                $errors[] = 'Assinatura do pacote inválida (base64).';
            } else {
                $ok = openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256);
                if ($ok !== 1) {
                    $errors[] = 'Assinatura do pacote não confere (.getfy-signature).';
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<int, string>  $excludeBasenames
     */
    public static function hashDirectory(string $dir, array $excludeBasenames = []): string
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $base = $file->getFilename();
            if (in_array($base, $excludeBasenames, true)) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            $files[$rel] = hash_file('sha256', $file->getPathname()) ?: '';
        }
        ksort($files);
        $ctx = hash_init('sha256');
        foreach ($files as $rel => $hash) {
            hash_update($ctx, $rel."\n".$hash."\n");
        }

        return hash_final($ctx);
    }
}
