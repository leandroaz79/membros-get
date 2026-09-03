<?php

use App\PluginSdk\Getfy;
use App\Plugins\Commerce\CommerceCheckoutContextRegistry;

return function ($app, $events): void {
    CommerceCheckoutContextRegistry::register('getfy-example-commerce', [
        'order_label' => fn ($order) => 'Example Commerce',
    ]);

    Getfy::hooks()->addAction('order.completed.context', function ($ctx): void {
        // Ex.: baixa de estoque / fulfillment quando metadata.plugin_checkout = getfy-example-commerce
    });
};
