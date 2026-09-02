<?php

namespace App\Support;

/**
 * Compatibilidade requires.getfy / requires.plugin_api do plugin.json.
 */
final class PluginRequirements
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    public static function validate(array $manifest): array
    {
        $errors = [];
        $requires = $manifest['requires'] ?? null;
        if ($requires === null) {
            return $errors;
        }
        if (! is_array($requires)) {
            return ['requires deve ser um objeto.'];
        }

        if (isset($requires['plugin_api'])) {
            $needed = (int) $requires['plugin_api'];
            $have = (int) config('plugins.plugin_api', 2);
            if ($needed > $have) {
                $errors[] = "requires.plugin_api={$needed} mas este Getfy expõe plugin_api={$have}.";
            }
        }

        if (isset($requires['getfy'])) {
            $constraint = trim((string) $requires['getfy']);
            $version = (string) config('getfy.version', '0.0.0');
            if ($constraint !== '' && ! self::satisfiesGetfy($version, $constraint)) {
                $errors[] = "requires.getfy=\"{$constraint}\" não satisfeito pela versão {$version}.";
            }
        }

        return $errors;
    }

    /**
     * Aceita: "2.0.4", ">=2.0.0", ">2.0.0", "^2.0", "~2.0.4"
     */
    public static function satisfiesGetfy(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        $version = ltrim(trim($version), 'v');
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        if (preg_match('/^\d+\.\d+/', $constraint)) {
            return version_compare($version, ltrim($constraint, 'v'), '>=');
        }

        if (str_starts_with($constraint, '^')) {
            $base = ltrim(substr($constraint, 1), 'v');
            if (! preg_match('/^(\d+)\./', $base, $m)) {
                return false;
            }
            $nextMajor = ((int) $m[1] + 1).'.0.0';

            return version_compare($version, $base, '>=') && version_compare($version, $nextMajor, '<');
        }

        if (str_starts_with($constraint, '~')) {
            $base = ltrim(substr($constraint, 1), 'v');
            $parts = array_map('intval', explode('.', $base));
            $nextMinor = ($parts[0] ?? 0).'.'.(($parts[1] ?? 0) + 1).'.0';

            return version_compare($version, $base, '>=') && version_compare($version, $nextMinor, '<');
        }

        if (preg_match('/^(>=|>|<=|<|=)\s*(.+)$/', $constraint, $m)) {
            $op = $m[1] === '=' ? '==' : $m[1];

            return version_compare($version, ltrim($m[2], 'v'), $op);
        }

        return false;
    }
}
