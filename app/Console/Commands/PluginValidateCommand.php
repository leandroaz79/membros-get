<?php

namespace App\Console\Commands;

use App\Plugins\PluginClassAutoloader;
use App\Plugins\PluginExtensionRegistry;
use App\Plugins\PluginRegistry;
use Illuminate\Console\Command;

class PluginValidateCommand extends Command
{
    protected $signature = 'plugin:validate {slug : Slug do plugin}';

    protected $description = 'Valida plugin.json, bootstrap, dist/frontend e rotas do plugin';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $dir = PluginRegistry::resolvePluginDirectory($slug);
        if ($dir === null || ! is_dir($dir)) {
            $this->error("Plugin \"{$slug}\" não encontrado.");

            return self::FAILURE;
        }

        $errors = PluginRegistry::validatePluginPackage($dir);
        $srcPath = $dir.DIRECTORY_SEPARATOR.'src';
        if (is_dir($srcPath)) {
            $expectedNs = 'Plugins\\'.PluginClassAutoloader::slugToPascalCase($slug).'\\';
            $this->line("  autoload: namespace esperado {$expectedNs} → src/");
        }
        if ($errors === []) {
            $this->info("Plugin \"{$slug}\" válido.");
            if (PluginExtensionRegistry::hasRuntimeFrontend(['path' => $dir, 'slug' => $slug, 'frontend' => PluginRegistry::readManifest($dir)['frontend'] ?? null])) {
                $this->line('  frontend: dist/ui.manifest.json OK');
            }

            return self::SUCCESS;
        }

        $this->error("Plugin \"{$slug}\" com problemas:");
        foreach ($errors as $err) {
            $this->line('  - '.$err);
        }

        return self::FAILURE;
    }
}
