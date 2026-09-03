<?php

namespace App\Console\Commands;

use App\Plugins\PluginRegistry;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class PluginBuildCommand extends Command
{
    protected $signature = 'plugin:build {slug : Slug do plugin}';

    protected $description = 'Executa npm run build no frontend do plugin';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $dir = PluginRegistry::resolvePluginDirectory($slug);
        if ($dir === null) {
            $this->error("Plugin \"{$slug}\" não encontrado.");

            return self::FAILURE;
        }
        $frontend = $dir.DIRECTORY_SEPARATOR.'frontend';
        if (! is_dir($frontend) || ! is_file($frontend.DIRECTORY_SEPARATOR.'package.json')) {
            $this->error('Pasta frontend/ com package.json não encontrada.');

            return self::FAILURE;
        }

        $npm = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'npm.cmd' : 'npm';
        if (! is_dir($frontend.DIRECTORY_SEPARATOR.'node_modules')) {
            $install = new Process([$npm, 'install'], $frontend, null, null, 600);
            $install->run(fn ($type, $buffer) => $this->output->write($buffer));
            if (! $install->isSuccessful()) {
                $this->error('npm install falhou.');

                return self::FAILURE;
            }
        }

        $build = new Process([$npm, 'run', 'build'], $frontend, null, null, 600);
        $build->run(fn ($type, $buffer) => $this->output->write($buffer));
        if (! $build->isSuccessful()) {
            $this->error('npm run build falhou.');

            return self::FAILURE;
        }

        $this->info("Build concluído: plugins/{$slug}/dist/");

        return self::SUCCESS;
    }
}
