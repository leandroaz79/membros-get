<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PixCheckoutDisplay
{
    public const EXPIRY_SECONDS = 900;

    /**
     * @param  array<string, mixed>  $pixResult
     */
    public static function persistOnOrder(Order $order, array $pixResult): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['pix_qrcode'] = $pixResult['qrcode'] ?? null;
        $meta['pix_copy_paste'] = $pixResult['copy_paste'] ?? null;
        $meta['pix_generated_at'] = time();
        $order->update(['metadata' => $meta]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function storeSession(Order $order, array $pixResult, array $context = []): string
    {
        $token = Str::random(32);
        $generatedAt = (int) ($context['created_at'] ?? time());

        $payload = array_merge([
            'order_id' => $order->id,
            'qrcode' => $pixResult['qrcode'] ?? null,
            'copy_paste' => $pixResult['copy_paste'] ?? null,
            'amount' => (float) ($context['amount'] ?? $order->amount),
            'product_name' => $context['product_name'] ?? $order->product?->name,
            'checkout_slug' => $context['checkout_slug'] ?? $order->getCheckoutSlug(),
            'redirect_after_purchase' => $context['redirect_after_purchase'] ?? null,
            'customer_name' => $context['customer_name'] ?? null,
            'customer_email' => $context['customer_email'] ?? $order->email,
            'customer_phone' => $context['customer_phone'] ?? $order->phone,
            'created_at' => $generatedAt,
        ], $context);

        self::putDisplayData($token, $payload);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function putDisplayData(string $token, array $data): void
    {
        session()->put('pix_display.'.$token, $data);
        Cache::put(self::cacheKey($token), $data, self::EXPIRY_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getDisplayData(string $token): ?array
    {
        $stored = session('pix_display.'.$token);
        if (is_array($stored)) {
            return $stored;
        }

        $cached = Cache::get(self::cacheKey($token));

        return is_array($cached) ? $cached : null;
    }

    public static function forgetDisplayData(string $token): void
    {
        session()->forget('pix_display.'.$token);
        Cache::forget(self::cacheKey($token));
    }

    private static function cacheKey(string $token): string
    {
        return 'pix_display.'.$token;
    }

    public static function persistAndStoreSession(Order $order, array $pixResult, array $context = []): string
    {
        static::persistOnOrder($order, $pixResult);

        return static::storeSession($order, $pixResult, $context);
    }

    public static function isPixMetadataValid(array $metadata): bool
    {
        $generatedAt = (int) ($metadata['pix_generated_at'] ?? 0);
        if ($generatedAt <= 0) {
            return false;
        }
        if ($generatedAt + self::EXPIRY_SECONDS < time()) {
            return false;
        }

        $copyPaste = trim((string) ($metadata['pix_copy_paste'] ?? ''));
        $qrcode = trim((string) ($metadata['pix_qrcode'] ?? ''));

        return $copyPaste !== '' || $qrcode !== '';
    }

    public static function hasPixPayload(array $metadata): bool
    {
        $copyPaste = trim((string) ($metadata['pix_copy_paste'] ?? ''));
        $qrcode = trim((string) ($metadata['pix_qrcode'] ?? ''));

        return $copyPaste !== '' || $qrcode !== '';
    }

    /**
     * @return array{qrcode: ?string, copy_paste: ?string, created_at: int}|null
     */
    public static function pixDataFromOrder(Order $order, bool $relaxed = false): ?array
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! $relaxed && ! static::isPixMetadataValid($meta)) {
            return null;
        }
        if ($relaxed && ! static::hasPixPayload($meta)) {
            return null;
        }

        $generatedAt = (int) ($meta['pix_generated_at'] ?? 0);

        return [
            'qrcode' => $meta['pix_qrcode'] ?? null,
            'copy_paste' => $meta['pix_copy_paste'] ?? null,
            'created_at' => $generatedAt > 0 ? $generatedAt : time(),
        ];
    }
}
