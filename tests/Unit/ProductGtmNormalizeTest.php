<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ConversionPixelsResolver;
use Tests\TestCase;

class ProductGtmNormalizeTest extends TestCase
{
    public function test_normalize_gtm_block_accepts_valid_container_id(): void
    {
        $result = Product::normalizeGtmBlock([
            'enabled' => true,
            'container_id' => 'gtm-abc123',
        ]);

        $this->assertTrue($result['enabled']);
        $this->assertSame('GTM-ABC123', $result['container_id']);
    }

    public function test_normalize_gtm_block_rejects_invalid_container_id(): void
    {
        $result = Product::normalizeGtmBlock([
            'enabled' => true,
            'container_id' => 'UA-12345-1',
        ]);

        $this->assertFalse($result['enabled']);
        $this->assertSame('', $result['container_id']);
    }

    public function test_resolver_includes_normalized_gtm_block(): void
    {
        $resolver = new ConversionPixelsResolver;
        $resolved = $resolver->resolveFromStoredArray([
            'gtm' => [
                'enabled' => true,
                'container_id' => 'GTM-TEST1',
            ],
        ], null);

        $this->assertTrue($resolved['gtm']['enabled']);
        $this->assertSame('GTM-TEST1', $resolved['gtm']['container_id']);
    }
}
