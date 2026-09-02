<?php

namespace Tests\Unit;

use App\Http\Middleware\PrepareCheckoutEmbed;
use App\Http\Middleware\SecurityHeaders;
use App\Support\CheckoutEmbed;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

class CheckoutEmbedTest extends TestCase
{
    public function test_normalize_config_empty_origins_means_any_site(): void
    {
        $normalized = CheckoutEmbed::normalizeConfig([
            'enabled' => true,
            'allowed_origins' => [],
        ]);

        $this->assertTrue($normalized['enabled']);
        $this->assertSame([], $normalized['allowed_origins']);
        $this->assertSame('*', CheckoutEmbed::frameAncestorsFromConfig(['embed' => $normalized]));
    }

    public function test_normalize_config_sanitizes_allowed_origins(): void
    {
        $normalized = CheckoutEmbed::normalizeConfig([
            'enabled' => true,
            'allowed_origins' => [
                'meusite.com',
                'https://www.outro.com:443',
                'invalid',
            ],
        ]);

        $this->assertSame(
            ['https://meusite.com', 'https://www.outro.com'],
            $normalized['allowed_origins']
        );
        $this->assertSame(
            'https://meusite.com https://www.outro.com',
            CheckoutEmbed::frameAncestorsFromConfig(['embed' => $normalized])
        );
    }

    public function test_security_headers_allow_framing_when_embed_context_is_set(): void
    {
        config(['app.env' => 'production']);

        $middleware = new SecurityHeaders;
        $request = Request::create('/c/demo', 'GET');
        $request->attributes->set('checkout_embed', [
            'allow_framing' => true,
            'frame_ancestors' => 'https://meusite.com',
        ]);

        $response = $middleware->handle($request, fn () => new Response('', 200));

        $this->assertFalse($response->headers->has('X-Frame-Options'));
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors https://meusite.com', $csp);
    }

    public function test_prepare_checkout_embed_sets_context_from_slug_when_enabled(): void
    {
        $product = $this->createTestProduct([
            'checkout_slug' => 'embeddemo',
            'checkout_config' => [
                'embed' => [
                    'enabled' => true,
                    'allowed_origins' => ['https://loja.test'],
                ],
            ],
        ]);

        $middleware = new PrepareCheckoutEmbed;
        $request = Request::create('/c/embeddemo', 'GET', [], [], [], ['HTTPS' => 'on']);
        $route = new \Illuminate\Routing\Route('GET', '/c/{slug}', []);
        $route->bind($request);
        $route->setParameter('slug', 'embeddemo');
        $request->setRouteResolver(fn () => $route);

        $session = new Store('test', new ArraySessionHandler(60));
        $request->setLaravelSession($session);

        $middleware->handle($request, fn () => new Response('', 200));

        $embed = $request->attributes->get('checkout_embed');
        $this->assertIsArray($embed);
        $this->assertTrue($embed['allow_framing']);
        $this->assertSame('https://loja.test', $embed['frame_ancestors']);
        $this->assertSame('none', config('session.same_site'));

        $product->delete();
    }
}
