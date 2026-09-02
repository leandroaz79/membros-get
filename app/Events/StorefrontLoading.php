<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;

class StorefrontLoading
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $tenantId,
        public Request $request,
        public ?string $routePrefix = null,
        public ?string $pluginSlug = null,
    ) {}
}
