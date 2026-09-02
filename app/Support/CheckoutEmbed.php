<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;

class CheckoutEmbed
{
    public const SESSION_ACTIVE_KEY = 'checkout_embed_active';

    public const SESSION_FRAME_ANCESTORS_KEY = 'checkout_embed_frame_ancestors';

    /**
     * @param  array<string, mixed>  $embed
     * @return array{enabled: bool, allowed_origins: list<string>}
     */
    public static function normalizeConfig(array $embed): array
    {
        $origins = $embed['allowed_origins'] ?? [];
        if (! is_array($origins)) {
            $origins = is_string($origins) && trim($origins) !== ''
                ? preg_split('/[\r\n,]+/', $origins) ?: []
                : [];
        }

        $allowed = [];
        foreach ($origins as $origin) {
            $sanitized = self::sanitizeOrigin((string) $origin);
            if ($sanitized !== '') {
                $allowed[] = $sanitized;
            }
        }

        return [
            'enabled' => filter_var($embed['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'allowed_origins' => array_values(array_unique($allowed)),
        ];
    }

    /**
     * @param  array<string, mixed>  $checkoutConfig
     */
    public static function isEnabledInConfig(array $checkoutConfig): bool
    {
        return self::normalizeConfig(is_array($checkoutConfig['embed'] ?? null) ? $checkoutConfig['embed'] : [])['enabled'];
    }

    /**
     * @param  array<string, mixed>  $checkoutConfig
     * @return array{enabled: bool, allowed_origins: list<string>}
     */
    public static function embedFromCheckoutConfig(array $checkoutConfig): array
    {
        return self::normalizeConfig(is_array($checkoutConfig['embed'] ?? null) ? $checkoutConfig['embed'] : []);
    }

    /**
     * Valor da diretiva CSP frame-ancestors (sem o nome da diretiva).
     *
     * @param  array<string, mixed>  $checkoutConfig
     */
    public static function frameAncestorsFromConfig(array $checkoutConfig): string
    {
        $embed = self::embedFromCheckoutConfig($checkoutConfig);
        if (! $embed['enabled']) {
            return "'self'";
        }

        if ($embed['allowed_origins'] === []) {
            return '*';
        }

        return implode(' ', $embed['allowed_origins']);
    }

    /**
     * @return array{enabled: bool, allowed_origins: list<string>}|null
     */
    public static function resolveBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $offer = ProductOffer::where('checkout_slug', $slug)->with('product')->first();
        if ($offer && $offer->product && $offer->product->is_active) {
            return self::embedForProductContext($offer->product, $offer, null);
        }

        $plan = SubscriptionPlan::where('checkout_slug', $slug)->with('product')->first();
        if ($plan && $plan->product && $plan->product->is_active) {
            return self::embedForProductContext($plan->product, null, $plan);
        }

        $product = Product::where('checkout_slug', $slug)->where('is_active', true)->first();
        if ($product) {
            return self::embedForProductContext($product, null, null);
        }

        return null;
    }

    /**
     * @return array{enabled: bool, allowed_origins: list<string>}|null
     */
    public static function resolveByProductId(string $productId): ?array
    {
        $product = Product::where('id', $productId)->where('is_active', true)->first();
        if (! $product) {
            return null;
        }

        return self::embedForProductContext($product, null, null);
    }

    /**
     * @return array{enabled: bool, allowed_origins: list<string>}
     */
    private static function embedForProductContext(
        Product $product,
        ?ProductOffer $offer,
        ?SubscriptionPlan $plan
    ): array {
        $defaults = Product::defaultCheckoutConfig();
        $config = array_replace_recursive($defaults, $product->checkout_config ?? []);

        if ($offer) {
            $config = array_replace_recursive($config, $offer->checkout_config ?? []);
        }
        if ($plan) {
            $config = array_replace_recursive($config, $plan->checkout_config ?? []);
        }

        return self::embedFromCheckoutConfig($config);
    }

    public static function sanitizeOrigin(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $origin = $scheme.'://'.$host;
        if ($port !== null && $port !== $defaultPort) {
            $origin .= ':'.$port;
        }

        return $origin;
    }
}
