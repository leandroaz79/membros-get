<?php

namespace App\Http\Controllers;

use App\Plugins\PluginRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serve assets estáticos de plugins (assets/, dist/) em GET /plugins/{slug}/assets/{path}.
 */
class PluginAssetController extends Controller
{
    public function __invoke(Request $request, string $slug, string $path): Response|BinaryFileResponse
    {
        $pluginDir = PluginRegistry::resolvePluginDirectory($slug);
        if ($pluginDir === null || ! is_dir($pluginDir)) {
            abort(404);
        }

        $path = str_replace(['../', '..\\'], '', $path);
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || preg_match('/\\.\\./', $path)) {
            abort(404);
        }

        $serveFromDist = str_starts_with($path, 'dist/');
        $baseDir = $serveFromDist
            ? $pluginDir
            : $pluginDir.DIRECTORY_SEPARATOR.'assets';
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $path);

        $fullPath = $baseDir.DIRECTORY_SEPARATOR.$relative;
        $realBase = realpath($baseDir);
        $realFile = realpath($fullPath);

        if ($realBase === false || $realFile === false) {
            abort(404);
        }
        $prefix = $realBase.DIRECTORY_SEPARATOR;
        if (! str_starts_with($realFile, $prefix)) {
            abort(404);
        }
        if (! is_file($realFile)) {
            abort(404);
        }

        $mime = match (strtolower(pathinfo($realFile, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'css' => 'text/css; charset=UTF-8',
            'js', 'mjs' => 'application/javascript; charset=UTF-8',
            'json' => 'application/json',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'map' => 'application/json',
            default => 'application/octet-stream',
        };

        $maxAge = $serveFromDist ? 31536000 : 86400;

        return response()->file($realFile, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age='.$maxAge,
        ]);
    }
}
