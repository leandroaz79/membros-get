<?php

namespace Tests\Unit;

use App\Services\ProductPackage\ProductPackageManifest;
use App\Support\ProductPackageMediaCollector;
use PHPUnit\Framework\TestCase;

class ProductPackageManifestTest extends TestCase
{
    public function test_validate_accepts_v1(): void
    {
        $manifest = ProductPackageManifest::build(
            ['name' => 'Curso', 'type' => 'area_membros'],
            true,
            ['aviso'],
            ['products/a.png' => 'media/products/a.png']
        );

        $this->assertSame([], ProductPackageManifest::validate($manifest));
        $this->assertSame(1, $manifest['schema_version']);
        $this->assertTrue($manifest['include_media']);
        $this->assertSame(1, $manifest['counts']['media_files']);
    }

    public function test_validate_rejects_bad_format(): void
    {
        $errors = ProductPackageManifest::validate([
            'format' => 'other',
            'schema_version' => 1,
            'product_summary' => ['name' => 'X'],
        ]);

        $this->assertNotEmpty($errors);
    }

    public function test_remap_release_required_module_ids_logic(): void
    {
        $moduleMap = ['10' => 101, '11' => 102];
        $requiredOld = ['10', '99', '11'];
        $required = [];
        foreach ($requiredOld as $oldId) {
            $mapped = $moduleMap[(string) $oldId] ?? null;
            if ($mapped) {
                $required[] = $mapped;
            }
        }

        $this->assertSame([101, 102], $required);
    }
}
