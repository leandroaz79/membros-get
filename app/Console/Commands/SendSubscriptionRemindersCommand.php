<?php

namespace App\Console\Commands;

use App\Jobs\SendSubscriptionRemindersJob;
use Illuminate\Console\Command;

/**
 * Roda o ciclo de lembretes de forma síncrona (sem depender de queue worker).
 * O job enfileirado ainda existe para instalações que preferem filas.
 */
class SendSubscriptionRemindersCommand extends Command
{
    protected $signature = 'subscriptions:send-reminders';

    protected $description = 'Processa lifecycle de assinaturas e envia lembretes / PIX de renovação';

    public function handle(): int
    {
        (new SendSubscriptionRemindersJob)->handle(
            app(\App\Services\TenantMailConfigService::class),
            app(\App\Services\SubscriptionLifecycleService::class),
        );

        $this->info('Subscription reminders processed.');

        return self::SUCCESS;
    }
}
