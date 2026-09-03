<?php

namespace App\PluginSdk\Contracts;

/**
 * Contrato público estável para drivers de gateway em plugins.
 * Preferir este namespace em vez de App\Gateways\Contracts.
 */
interface GatewayDriver extends \App\Gateways\Contracts\GatewayDriver
{
}
