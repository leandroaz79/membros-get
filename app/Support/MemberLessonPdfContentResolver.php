<?php

namespace App\Support;

use App\Services\StorageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class MemberLessonPdfContentResolver
{
    public function __construct(private StorageService $storage) {}

    /**
     * @return array{body: string, filename: string}
     */
    public function fetch(string $sourceUrl): array
    {
        $sourceUrl = trim($sourceUrl);
        if ($sourceUrl === '') {
            abort(404, 'Arquivo não encontrado.');
        }

        $fromStorage = $this->readFromStorage($sourceUrl);
        if ($fromStorage !== null) {
            return $fromStorage;
        }

        if (! SafeRemoteUrl::isAllowedHttpUrl($sourceUrl)) {
            abort(403, 'URL do arquivo não permitida.');
        }

        $remote = Http::timeout(120)->connectTimeout(30)->get($sourceUrl);
        if (! $remote->successful()) {
            abort(502, 'Não foi possível obter o arquivo.');
        }

        return [
            'body' => $remote->body(),
            'filename' => $this->filenameFromUrl($sourceUrl),
        ];
    }

    /**
     * @return array{body: string, filename: string}|null
     */
    private function readFromStorage(string $sourceUrl): ?array
    {
        $path = $this->storage->pathFromUrl($sourceUrl);
        if ($path === null) {
            return null;
        }

        if ($this->storage->exists($path)) {
            $body = $this->storage->disk()->get($path);
            if (is_string($body) && $body !== '') {
                return [
                    'body' => $body,
                    'filename' => $this->filenameFromPath($path),
                ];
            }
        }

        if (Storage::disk('public')->exists($path)) {
            $body = Storage::disk('public')->get($path);
            if (is_string($body) && $body !== '') {
                return [
                    'body' => $body,
                    'filename' => $this->filenameFromPath($path),
                ];
            }
        }

        abort(404, 'Arquivo PDF não encontrado no storage.');
    }

    private function filenameFromPath(string $path): string
    {
        $name = basename($path);

        return ($name !== '' && $name !== '/') ? $name : 'apresentacao.pdf';
    }

    private function filenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $name = basename($path);
            if ($name !== '' && $name !== '/') {
                return $name;
            }
        }

        return 'apresentacao.pdf';
    }
}
