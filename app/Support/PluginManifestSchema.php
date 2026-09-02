<?php

namespace App\Support;

/**
 * Validação leve do plugin.json contra resources/schemas/plugin.schema.json
 * (subset: required + tipos básicos — sem dependência de lib externa).
 */
final class PluginManifestSchema
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    public static function validate(array $manifest): array
    {
        $errors = [];
        foreach (['slug', 'name', 'version'] as $key) {
            if (! isset($manifest[$key]) || ! is_string($manifest[$key]) || trim($manifest[$key]) === '') {
                $errors[] = "plugin.json: campo obrigatório \"{$key}\" ausente ou inválido.";
            }
        }
        if (isset($manifest['slug']) && is_string($manifest['slug'])
            && ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $manifest['slug'])) {
            $errors[] = 'plugin.json: slug deve ser kebab-case (a-z0-9-).';
        }
        if (isset($manifest['version']) && is_string($manifest['version'])
            && ! preg_match('/^\d+\.\d+\.\d+/', $manifest['version'])) {
            $errors[] = 'plugin.json: version deve seguir SemVer (ex.: 1.0.0).';
        }
        if (isset($manifest['type']) && is_string($manifest['type'])) {
            $allowed = ['generic', 'gateway', 'payment_gateway', 'commerce', 'integration', 'member', 'theme'];
            if (! in_array($manifest['type'], $allowed, true)) {
                $errors[] = 'plugin.json: type inválido.';
            }
        }
        if (isset($manifest['requires']) && ! is_array($manifest['requires'])) {
            $errors[] = 'plugin.json: requires deve ser um objeto.';
        }
        if (isset($manifest['frontend']) && ! is_array($manifest['frontend'])) {
            $errors[] = 'plugin.json: frontend deve ser um objeto.';
        }

        return $errors;
    }

    public static function schemaPath(): string
    {
        return resource_path('schemas/plugin.schema.json');
    }
}
