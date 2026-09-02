<?php

namespace App\Http\Controllers;

use App\Plugins\PluginExtensionRegistry;
use App\Plugins\PluginRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renderiza páginas Inertia de plugins: Plugin/{slug}/{Page} (slug literal, ex. kebab-case).
 */
class PluginInertiaController extends Controller
{
    public function show(Request $request, ?string $page = null): Response
    {
        $slug = (string) ($request->route('plugin_slug') ?? $request->segment(1) ?? '');
        if ($slug === '') {
            abort(404);
        }

        $page = $page ?? (string) ($request->route('page') ?? $request->segment(2) ?? 'Index');
        $page = trim($page, '/');
        if ($page === '') {
            $page = 'Index';
        }

        $plugin = collect(PluginRegistry::enabled())->firstWhere('slug', $slug);
        if ($plugin === null) {
            abort(404);
        }

        $componentName = 'Plugin/'.$slug.'/'.$page;

        $props = [
            'pluginSlug' => $slug,
            'pluginPage' => $page,
        ];

        if ($plugin !== null && PluginExtensionRegistry::hasRuntimeFrontend($plugin)) {
            $export = PluginExtensionRegistry::resolvePageExport($plugin, $page);
            if ($export !== null) {
                $props['plugin_ui_page'] = [
                    'plugin_slug' => $slug,
                    'page' => $page,
                    'ui_export' => $export,
                    'ui_mode' => 'runtime',
                ];
            }
        }

        return Inertia::render($componentName, $props);
    }
}
