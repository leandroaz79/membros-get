<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class IntegraXPixLinkResolver
{
    /**
     * @param  array<string, mixed>|null  $pixPayload  Dados do evento PixGenerated (antes da persistência no pedido).
     */
    public static function urlForOrder(Order $order, ?array $pixPayload = null): ?string
    {
        $order->loadMissing(['product', 'user']);
        $pixData = self::resolvePixData($order, $pixPayload);
        if ($pixData === null) {
            return null;
        }

        $product = $order->product;
        $redirectAfterPurchase = $product?->checkout_config['redirect_after_purchase'] ?? null;
        $redirectAfterPurchase = is_string($redirectAfterPurchase) && trim($redirectAfterPurchase) !== ''
            ? $redirectAfterPurchase
            : null;

        $token = Str::random(32);
        $data = [
            'order_id' => $order->id,
            'qrcode' => $pixData['qrcode'] ?? null,
            'copy_paste' => $pixData['copy_paste'] ?? null,
            'amount' => (float) $order->amount,
            'product_name' => $product?->name,
            'checkout_slug' => $order->getCheckoutSlug(),
            'redirect_after_purchase' => $redirectAfterPurchase,
            'customer_name' => $order->user?->name,
            'customer_email' => $order->email,
            'customer_phone' => $order->phone,
            'created_at' => (int) ($pixData['created_at'] ?? time()),
        ];

        PixCheckoutDisplay::putDisplayData($token, $data);

        return route('checkout.pix', ['token' => $token]);
    }

    /**
     * @param  array<string, mixed>|null  $pixPayload
     * @return array{qrcode: ?string, copy_paste: ?string, created_at: int}|null
     */
    private static function resolvePixData(Order $order, ?array $pixPayload): ?array
    {
        if (is_array($pixPayload)) {
            $copyPaste = trim((string) ($pixPayload['copy_paste'] ?? ''));
            $qrcode = trim((string) ($pixPayload['qrcode'] ?? ''));
            if ($copyPaste !== '' || $qrcode !== '') {
                return [
                    'qrcode' => $pixPayload['qrcode'] ?? null,
                    'copy_paste' => $pixPayload['copy_paste'] ?? null,
                    'created_at' => time(),
                ];
            }
        }

        return PixCheckoutDisplay::pixDataFromOrder($order, relaxed: true);
    }
}
