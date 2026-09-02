<?php

namespace App\Console\Commands;

use App\Support\VapidKeysManager;
use Illuminate\Console\Command;

class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'pwa:vapid {--force : Regenera mesmo se já existirem chaves válidas}';

    protected $description = 'Gera chaves VAPID para PWA (notificações push) e atualiza o .env';

    public function handle(VapidKeysManager $manager): int
    {
        $force = (bool) $this->option('force');
        $result = $force ? $manager->generate(true) : $manager->ensureConfigured(false);

        if (! ($result['success'] ?? false)) {
            $this->error($result['message'] ?? 'Falha ao gerar chaves VAPID.');

            return self::FAILURE;
        }

        if ($result['already_configured'] ?? false) {
            $this->info($result['message']);
            $this->comment('Use --force para regenerar (inscrições push existentes precisarão ser reativadas).');

            return self::SUCCESS;
        }

        if ($result['restored_from_shared'] ?? false) {
            $this->info($result['message']);

            return self::SUCCESS;
        }

        $this->info('Chaves VAPID geradas e salvas no .env.');
        $this->line('');
        if (! empty($result['public_key'])) {
            $this->line('PWA_VAPID_PUBLIC='.$result['public_key']);
        }
        $this->line('PWA_VAPID_PRIVATE='.str_repeat('*', 20).'...');
        $this->line('');
        $this->comment('Reinicie o servidor ou rode "php artisan config:clear" para carregar as novas variáveis.');
        $this->comment('Usuários com push ativo devem reativar notificações no painel após trocar o par VAPID.');

        return self::SUCCESS;
    }
}
