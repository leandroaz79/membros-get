<?php

namespace App\Http\Middleware;

use App\Services\PartnerAccessService;
use App\Services\TeamAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(403, 'Acesso não autorizado.');
        }

        // Admin/infoprodutor passam (acesso total do tenant).
        if ($user->isAdmin() || $user->isInfoprodutor()) {
            return $next($request);
        }

        if ($user->isTeam()) {
            $access = app(TeamAccessService::class);
            if ($access->can($user, $permission)) {
                return $next($request);
            }

            abort(403, 'Acesso não autorizado.');
        }

        // Parceiros (afiliado/co-produtor) que caírem em rotas do produtor (/financeiro, /dashboard)
        // são redirecionados ao painel correto em vez de 403.
        if (app(PartnerAccessService::class)->usesPartnerPanel($user)) {
            if ($permission === 'financeiro.view') {
                return redirect('/parceiro/financeiro');
            }
            if ($permission === 'dashboard.view') {
                return redirect('/parceiro');
            }
        }

        abort(403, 'Acesso não autorizado.');
    }
}

