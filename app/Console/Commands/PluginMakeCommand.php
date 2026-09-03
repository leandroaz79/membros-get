<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PluginMakeCommand extends Command
{
    protected $signature = 'plugin:make
        {slug : Slug do plugin (ex: meu-plugin)}
        {--type=blank : blank|gateway|commerce|integration|member}';

    protected $description = 'Gera estrutura de plugin a partir do starter / exemplos oficiais';

    public function handle(): int
    {
        $slug = Str::slug((string) $this->argument('slug'));
        if ($slug === '') {
            $this->error('Slug inválido.');

            return self::FAILURE;
        }

        $type = strtolower((string) $this->option('type'));
        $starterMap = [
            'blank' => 'getfy-plugin-starter',
            'generic' => 'getfy-plugin-starter',
            'gateway' => 'getfy-example-gateway',
            'payment_gateway' => 'getfy-example-gateway',
            'commerce' => 'getfy-example-commerce',
            'integration' => 'getfy-example-integration',
            'member' => 'getfy-example-member',
        ];
        if (! isset($starterMap[$type])) {
            $this->error('Type inválido. Use: blank, gateway, commerce, integration, member.');

            return self::FAILURE;
        }

        $starterName = $starterMap[$type];
        $starter = base_path('plugins/'.$starterName);
        $target = base_path('plugins/'.$slug);
        if (is_dir($target)) {
            $this->error("Já existe: plugins/{$slug}");

            return self::FAILURE;
        }
        if (! is_dir($starter)) {
            $this->error("Starter plugins/{$starterName} não encontrado.");

            return self::FAILURE;
        }

        File::copyDirectory($starter, $target);
        $this->replaceInTree($target, $starterName, $slug);
        $pascalFrom = Str::studly(str_replace('-', ' ', $starterName));
        $pascalTo = Str::studly(str_replace('-', ' ', $slug));
        if ($pascalFrom !== $pascalTo) {
            $this->replaceInTree($target, $pascalFrom, $pascalTo);
            $this->replaceInTree($target, 'Plugins\\'.$pascalFrom, 'Plugins\\'.$pascalTo);
        }
        $headlineFrom = match ($starterName) {
            'getfy-plugin-starter' => 'Getfy Plugin Starter',
            'getfy-example-gateway' => 'Getfy Example Gateway',
            'getfy-example-commerce' => 'Getfy Example Commerce',
            'getfy-example-integration' => 'Getfy Example Integration',
            'getfy-example-member' => 'Getfy Example Member',
            default => Str::headline(str_replace('-', ' ', $starterName)),
        };
        $this->replaceInTree($target, $headlineFrom, Str::headline(str_replace('-', ' ', $slug)));

        // Gateway example also uses gateway slug "example-gateway"
        if ($type === 'gateway' || $type === 'payment_gateway') {
            $this->replaceInTree($target, 'example-gateway', $slug);
            $this->replaceInTree($target, 'Example Gateway', Str::headline(str_replace('-', ' ', $slug)));
        }

        $this->info("Plugin criado em plugins/{$slug} (type={$type})");
        $this->line('  1. Edite plugin.json e src/');
        if (is_dir($target.'/frontend')) {
            $this->line('  2. cd plugins/'.$slug.'/frontend && npm install && npm run build');
            $this->line('  3. php artisan plugin:validate '.$slug);
        } else {
            $this->line('  2. php artisan plugin:validate '.$slug);
        }

        return self::SUCCESS;
    }

    private function replaceInTree(string $dir, string $search, string $replace): void
    {
        if ($search === '' || $search === $replace) {
            return;
        }
        foreach (File::allFiles($dir) as $file) {
            $path = $file->getPathname();
            if (str_contains($path, 'node_modules') || str_contains($path, DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR)) {
                // Ainda substitui em dist para slug do window.__GETFY_PLUGIN_UI__
            }
            if (str_contains($path, 'node_modules')) {
                continue;
            }
            $content = file_get_contents($path);
            if (is_string($content) && str_contains($content, $search)) {
                file_put_contents($path, str_replace($search, $replace, $content));
            }
        }
        foreach (File::directories($dir) as $sub) {
            if (basename($sub) === 'node_modules') {
                continue;
            }
            $this->replaceInTree($sub, $search, $replace);
            $newName = str_replace($search, $replace, basename($sub));
            if ($newName !== basename($sub)) {
                File::moveDirectory($sub, dirname($sub).DIRECTORY_SEPARATOR.$newName);
            }
        }
    }
}
