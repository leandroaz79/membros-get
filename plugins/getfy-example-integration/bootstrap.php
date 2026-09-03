<?php

use App\PluginSdk\Getfy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

return function ($app, $events): void {
    Getfy::hooks()->addAction('order.completed', function ($order): void {
        $cfg = Getfy::config()->get('getfy-example-integration');
        $url = trim((string) ($cfg['webhook_url'] ?? ''));
        if ($url === '') {
            return;
        }
        try {
            Http::timeout(8)->post($url, [
                'event' => 'order.completed',
                'order_id' => $order->id,
                'status' => $order->status,
                'amount' => $order->amount,
            ]);
        } catch (\Throwable $e) {
            Log::warning('example-integration webhook failed', ['error' => $e->getMessage()]);
        }
    });
};
