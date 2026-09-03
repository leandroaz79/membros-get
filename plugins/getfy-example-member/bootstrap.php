<?php

use App\PluginSdk\Getfy;

return function ($app, $events): void {
    Getfy::hooks()->addFilter('member_area.payload', function (array $payload, $product, $user, $request) {
        $payload['plugin_example_member'] = [
            'message' => 'Olá da Getfy Example Member',
            'product_id' => $product->id ?? null,
        ];

        return $payload;
    });
};
