<?php

/**
 * Plugins instalados via ZIP/loja:
 * - GETFY_PLUGINS_USER_PATH definido: usa esse caminho absoluto.
 * - GETFY_DOCKER=true (Compose): `.docker/plugins-installed` — mesmo volume que `getfy_env` (.docker), separado de `storage`.
 * - Caso contrário: `storage/app/plugins-installed`.
 *
 * GETFY_PLUGINS_EXTRA_SCAN: pastas extras só de leitura, separadas por | (opcional).
 */
return [
    'user_install_path' => env('GETFY_PLUGINS_USER_PATH') ?: null,

    'docker_mode' => filter_var(env('GETFY_DOCKER', false), FILTER_VALIDATE_BOOLEAN),

    'extra_scan_paths' => array_values(array_filter(
        array_map('trim', explode('|', (string) env('GETFY_PLUGINS_EXTRA_SCAN', '')))
    )),

    /**
     * Versão da API pública de plugins (App\PluginSdk).
     * Plugins declaram requires.plugin_api no plugin.json.
     */
    'plugin_api' => (int) env('GETFY_PLUGIN_API', 2),

    /** Tamanho máximo total de plugins/{slug}/dist/ na validação (bytes). */
    'max_dist_bytes' => (int) env('GETFY_PLUGINS_MAX_DIST_BYTES', 15 * 1024 * 1024),

    /** Carrinho commerce (lojas via plugin). */
    'commerce_cart_ttl_days' => (int) env('GETFY_COMMERCE_CART_TTL_DAYS', 14),
    'commerce_cart_max_lines' => (int) env('GETFY_COMMERCE_CART_MAX_LINES', 50),
    'commerce_checkout_ttl_hours' => (int) env('GETFY_COMMERCE_CHECKOUT_TTL_HOURS', 2),

    /** Licença: se true, falha de rede invalida o plugin; se false, fail-open. */
    'license_fail_closed' => filter_var(env('GETFY_PLUGIN_LICENSE_FAIL_CLOSED', false), FILTER_VALIDATE_BOOLEAN),
    'license_cache_seconds' => (int) env('GETFY_PLUGIN_LICENSE_CACHE_SECONDS', 3600),

    /**
     * Chave pública PEM (opcional) para verificar assinatura de ZIP da loja.
     * Se vazia, apenas checksum SHA-256 opcional em .getfy-checksum é validado.
     */
    'package_public_key' => env('GETFY_PLUGIN_PACKAGE_PUBLIC_KEY'),
];
