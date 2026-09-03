<?php

namespace App\PluginSdk;

use Illuminate\Support\Facades\Event;

/**
 * Helpers para listeners de eventos Laravel a partir de plugins.
 * Preferir declarar events no plugin.json; use esta API em bootstrap quando precisar de lógica dinâmica.
 */
class PluginEventsService
{
    /**
     * @param  class-string  $event
     * @param  class-string|callable  $listener
     */
    public function listen(string $event, string|callable $listener): void
    {
        Event::listen($event, $listener);
    }

    /**
     * @param  class-string  $event
     * @param  array<int, class-string|callable>  $listeners
     */
    public function listenMany(string $event, array $listeners): void
    {
        foreach ($listeners as $listener) {
            $this->listen($event, $listener);
        }
    }
}
