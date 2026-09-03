<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * Atualização via Git pelo painel (VPS com repositório no host).
 */
class GitPanelUpdater
{
    /**
     * @param  callable(string, string): bool  $runStep
     * @return array{ok: bool, message: string}
     */
    public static function run(string $basePath, string $branch, callable $runStep): array
    {
        $git = 'git -c safe.directory=' . escapeshellarg($basePath);

        $runStep($git . ' config user.email "getfy-update@localhost" && ' . $git . ' config user.name "Getfy Update"', 'Git config');

        $status = Process::path($basePath)->run($git . ' status --porcelain');
        $hasLocalChanges = $status->successful() && trim($status->output()) !== '';
        $didStash = false;

        if ($hasLocalChanges) {
            $stashCmd = $git . ' stash push -u -m "getfy-update" -- . '
                . escapeshellarg(':!.env') . ' '
                . escapeshellarg(':!.docker');
            $didStash = $runStep($stashCmd, 'Git stash');
        }

        $syncCmd = $git . ' fetch origin && '
            . $git . ' checkout -B ' . escapeshellarg($branch) . ' ' . escapeshellarg('origin/' . $branch) . ' && '
            . $git . ' reset --hard ' . escapeshellarg('origin/' . $branch);

        if (! $runStep($syncCmd, 'Git fetch/reset')) {
            if ($didStash) {
                self::tryStashPop($basePath, $git, $runStep);
            }

            return [
                'ok' => false,
                'message' => self::lastStepError($git, $basePath, 'Git fetch/reset'),
            ];
        }

        if ($didStash) {
            self::tryStashPop($basePath, $git, $runStep);
        }

        return ['ok' => true, 'message' => 'Código atualizado via Git.'];
    }

    /**
     * @param  callable(string, string): bool  $runStep
     */
    private static function tryStashPop(string $basePath, string $git, callable $runStep): void
    {
        $list = Process::path($basePath)->run($git . ' stash list');
        if (! $list->successful() || trim($list->output()) === '') {
            return;
        }

        $runStep($git . ' stash pop', 'Git stash pop');
    }

    private static function lastStepError(string $git, string $basePath, string $label): string
    {
        return 'Falha em ' . $label . '. Verifique permissões, conexão com GitHub e branch configurada.';
    }
}
