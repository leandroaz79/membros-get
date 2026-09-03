<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disparado antes de criar Order + CommerceCheckoutSession via PluginCommerceCheckoutStarter.
 * Listeners podem definir $abort para bloquear o checkout.
 */
class CommerceCheckoutBuilding
{
    use Dispatchable, SerializesModels;

    public ?string $abort = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $tenantId,
        public string $pluginSlug,
        public array $payload,
    ) {}
}
