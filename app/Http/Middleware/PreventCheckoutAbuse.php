<?php

namespace App\Http\Middleware;

use App\Services\CheckoutAbuseGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class PreventCheckoutAbuse
{
    public function __construct(
        private readonly CheckoutAbuseGuard $guard
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->guard->isEnabled()) {
            return $next($request);
        }

        if ($this->guard->honeypotTriggered($request)) {
            throw new TooManyRequestsHttpException(60, 'Muitas tentativas. Aguarde e tente novamente.');
        }

        return $next($request);
    }
}
