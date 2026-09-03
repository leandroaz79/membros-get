<?php

namespace Tests\Unit;

use App\Support\CajuPayPartnerCheckoutUrl;
use PHPUnit\Framework\TestCase;

class CajuPayPartnerCheckoutUrlTest extends TestCase
{
    public function test_sanitize_rejects_http_urls(): void
    {
        $this->assertNull(CajuPayPartnerCheckoutUrl::sanitize('http://localhost/c/foo'));
        $this->assertNull(CajuPayPartnerCheckoutUrl::sanitize('http://getfy-opensource.test/c/produto'));
    }

    public function test_sanitize_accepts_https_urls(): void
    {
        $url = 'https://loja.com/c/produto-test';

        $this->assertSame($url, CajuPayPartnerCheckoutUrl::sanitize($url));
    }

    public function test_sanitize_rejects_invalid_urls(): void
    {
        $this->assertNull(CajuPayPartnerCheckoutUrl::sanitize(''));
        $this->assertNull(CajuPayPartnerCheckoutUrl::sanitize('not-a-url'));
    }
}
