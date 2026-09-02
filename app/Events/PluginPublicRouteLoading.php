<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Routing\Router;

class PluginPublicRouteLoading
{
    use Dispatchable;

    public function __construct(
        public string $pluginSlug,
        public Router $router,
        public string $prefix
    ) {}
}
