<?php

use App\PluginSdk\Getfy;

/**
 * Bootstrap do plugin starter.
 *
 * @param  \Illuminate\Contracts\Foundation\Application  $app
 * @param  \Illuminate\Contracts\Events\Dispatcher  $events
 */
return function ($app, $events): void {
    Getfy::hooks()->addFilter('panel.menu', function (array $items) {
        // Exemplo: não altera o menu; use para adicionar itens se necessário.
        return $items;
    });

    Getfy::hooks()->addAction('order.completed', function ($order): void {
        // Exemplo: reagir a pedido pago sem depender de Event::listen.
    });
};
