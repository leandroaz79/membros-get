<?php

namespace App\Http\Middleware;

use App\Support\CheckoutEmbed;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrepareCheckoutEmbed
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isCheckoutFunnelRequest($request)) {
            $embed = $this->resolveEmbedContext($request);
            if ($embed !== null) {
                $request->attributes->set('checkout_embed', $embed);
                $this->configureSessionForThirdPartyEmbed($request);
            }
        }

        return $next($request);
    }

    private function isCheckoutFunnelRequest(Request $request): bool
    {
        $path = trim($request->path(), '/');

        if (preg_match('#^c/[^/]+$#', $path)) {
            return true;
        }

        if (str_starts_with($path, 'checkout')) {
            return true;
        }

        return str_starts_with($path, 'api/checkout');
    }

    /**
     * @return array{allow_framing: bool, frame_ancestors: string}|null
     */
    private function resolveEmbedContext(Request $request): ?array
    {
        if ($request->hasSession() && $request->session()->get(CheckoutEmbed::SESSION_ACTIVE_KEY)) {
            $ancestors = (string) $request->session()->get(
                CheckoutEmbed::SESSION_FRAME_ANCESTORS_KEY,
                '*'
            );

            return [
                'allow_framing' => true,
                'frame_ancestors' => $ancestors !== '' ? $ancestors : '*',
            ];
        }

        $slug = $request->route('slug');
        if (is_string($slug) && $slug !== '') {
            $embed = CheckoutEmbed::resolveBySlug($slug);
            if ($embed && $embed['enabled']) {
                return [
                    'allow_framing' => true,
                    'frame_ancestors' => CheckoutEmbed::frameAncestorsFromConfig(['embed' => $embed]),
                ];
            }
        }

        $productId = $request->input('product_id');
        if (is_string($productId) && $productId !== '') {
            $embed = CheckoutEmbed::resolveByProductId($productId);
            if ($embed && $embed['enabled']) {
                return [
                    'allow_framing' => true,
                    'frame_ancestors' => CheckoutEmbed::frameAncestorsFromConfig(['embed' => $embed]),
                ];
            }
        }

        return null;
    }

    private function configureSessionForThirdPartyEmbed(Request $request): void
    {
        // SameSite=None exige cookie Secure — só aplica em HTTPS para não quebrar sessão em dev local (HTTP).
        if (! $request->secure() && ! config('session.secure')) {
            return;
        }

        config([
            'session.same_site' => 'none',
            'session.secure' => true,
        ]);
    }
}
