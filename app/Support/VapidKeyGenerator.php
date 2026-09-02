<?php

namespace App\Support;

use Jose\Component\Core\JWK;
use Jose\Component\Core\Util\Base64UrlSafe;
use Jose\Component\KeyManagement\KeyConverter\ECKey;
use Minishlink\WebPush\Utils;
use Minishlink\WebPush\VAPID;

/**
 * Gera par VAPID (P-256) via lib web-push ou fallback openssl CLI (Windows/Laragon sem openssl.cnf).
 */
class VapidKeyGenerator
{
    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public static function createPair(): array
    {
        self::ensureOpenSslConf();

        try {
            return VAPID::createVapidKeys();
        } catch (\Throwable $first) {
            $viaCli = self::createViaOpensslCli();
            if ($viaCli !== null) {
                return $viaCli;
            }

            throw new \RuntimeException(
                'Não foi possível gerar chaves VAPID. Verifique se o OpenSSL do PHP suporta EC (P-256) ou instale/configure o binário openssl (no Laragon: bin/laragon/utils/openssl).',
                0,
                $first
            );
        }
    }

    private static function ensureOpenSslConf(): void
    {
        $existing = getenv('OPENSSL_CONF');
        if (is_string($existing) && trim($existing) !== '') {
            return;
        }

        $phpDir = dirname(PHP_BINARY);
        $candidates = [
            $phpDir.DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
            $phpDir.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
        ];

        foreach ($candidates as $cnf) {
            if (is_file($cnf)) {
                putenv('OPENSSL_CONF='.$cnf);
                $_ENV['OPENSSL_CONF'] = $cnf;
                $_SERVER['OPENSSL_CONF'] = $cnf;

                return;
            }
        }
    }

    /**
     * @return array{publicKey: string, privateKey: string}|null
     */
    private static function createViaOpensslCli(): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'vapid_');
        if ($tmp === false) {
            return null;
        }

        $pemFile = $tmp.'.pem';
        if (! @rename($tmp, $pemFile)) {
            @unlink($tmp);

            return null;
        }

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $generated = false;

        foreach (self::opensslCliCandidates() as $openssl) {
            $cmd = escapeshellarg($openssl).' ecparam -name prime256v1 -genkey -noout -out '.escapeshellarg($pemFile).' 2>'.$null;
            exec($cmd, $out, $code);
            if ($code === 0 && is_readable($pemFile) && filesize($pemFile) > 0) {
                $generated = true;
                break;
            }
        }

        if (! $generated) {
            @unlink($pemFile);

            return null;
        }

        $pem = file_get_contents($pemFile);
        @unlink($pemFile);
        if ($pem === false || $pem === '') {
            return null;
        }

        try {
            $jwkArray = ECKey::createFromPEM($pem)->toArray();
            $jwk = new JWK($jwkArray);
            $binaryPublicKey = hex2bin(Utils::serializePublicKeyFromJWK($jwk));
            if (! $binaryPublicKey || strlen($binaryPublicKey) !== 65) {
                return null;
            }
            $d = Base64UrlSafe::decode($jwk->get('d'));
            $binaryPrivateKey = str_pad($d, 32, "\0", STR_PAD_LEFT);
            if (strlen($binaryPrivateKey) !== 32) {
                return null;
            }

            return [
                'publicKey' => Base64UrlSafe::encode($binaryPublicKey),
                'privateKey' => Base64UrlSafe::encode($binaryPrivateKey),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function opensslCliCandidates(): array
    {
        $candidates = ['openssl'];

        if (PHP_OS_FAMILY !== 'Windows') {
            return $candidates;
        }

        $phpBin = PHP_BINARY;
        $phpDir = dirname($phpBin);
        $laragonBin = dirname(dirname($phpDir)); // .../laragon/bin

        $candidates[] = $laragonBin.DIRECTORY_SEPARATOR.'laragon'.DIRECTORY_SEPARATOR.'utils'.DIRECTORY_SEPARATOR.'openssl'.DIRECTORY_SEPARATOR.'openssl.exe';

        $apacheGlob = glob(dirname($laragonBin).DIRECTORY_SEPARATOR.'apache'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'openssl.exe');
        if (is_array($apacheGlob)) {
            foreach ($apacheGlob as $path) {
                $candidates[] = $path;
            }
        }

        $candidates[] = 'C:\\laragon\\bin\\laragon\\utils\\openssl\\openssl.exe';
        $candidates[] = 'C:\\xampp\\apache\\bin\\openssl.exe';

        $unique = [];
        foreach ($candidates as $candidate) {
            if ($candidate === 'openssl' || is_file($candidate)) {
                $unique[$candidate] = $candidate;
            }
        }

        return array_values($unique);
    }
}
