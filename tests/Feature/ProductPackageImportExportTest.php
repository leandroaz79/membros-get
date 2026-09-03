<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\User;
use App\Services\ProductPackage\ProductExportService;
use App\Services\ProductPackage\ProductImportService;
use App\Services\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ProductPackageImportExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
        Storage::fake('public');
    }

    /**
     * @return array{0: Product, 1: MemberModule, 2: MemberLesson}
     */
    private function seedMemberProduct(): array
    {
        $product = $this->createTestProduct([
            'name' => 'Curso Export',
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 97,
            'checkout_config' => array_replace_recursive(Product::defaultCheckoutConfig(), [
                'appearance' => ['primary_color' => '#112233'],
            ]),
            'member_area_config' => Product::defaultMemberAreaConfig(),
            'checkout_slug' => 'exp'.substr(uniqid('', true), -7),
        ]);

        ProductOffer::create([
            'product_id' => $product->id,
            'name' => 'Oferta especial',
            'price' => 47,
            'currency' => 'BRL',
            'position' => 1,
            'checkout_slug' => ProductOffer::generateUniqueCheckoutSlug(),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Módulo 1',
            'position' => 1,
            'cover_mode' => 'horizontal',
            'section_type' => 'courses',
        ]);

        $moduleA = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Intro',
            'position' => 1,
        ]);

        $moduleB = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Avançado',
            'position' => 2,
            'release_required_module_ids' => [$moduleA->id],
        ]);

        $lesson = MemberLesson::create(MemberLesson::onlyExistingColumns([
            'member_module_id' => $moduleA->id,
            'product_id' => $product->id,
            'title' => 'Aula 1',
            'position' => 1,
            'type' => MemberLesson::TYPE_VIDEO,
            'content_url' => 'https://youtube.com/watch?v=abc',
        ]));

        // silencia unused
        unset($moduleB);

        return [$product, $moduleA, $lesson];
    }

    public function test_export_zip_contains_manifest_and_product_json(): void
    {
        [$product] = $this->seedMemberProduct();
        $storage = new StorageService(1);
        $export = new ProductExportService($storage);

        $result = $export->export($product, false);
        $this->assertFileExists($result['path']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['path']) === true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $payload = json_decode((string) $zip->getFromName('product.json'), true);
        $zip->close();

        $this->assertSame('getfy-product', $manifest['format'] ?? null);
        $this->assertFalse($manifest['include_media']);
        $this->assertSame('Curso Export', $payload['product']['name'] ?? null);
        $this->assertCount(1, $payload['offers'] ?? []);
        $this->assertCount(1, $payload['member_sections'] ?? []);
        $this->assertSame('Aula 1', $payload['member_sections'][0]['modules'][0]['lessons'][0]['title'] ?? null);

        @unlink($result['path']);
    }

    public function test_export_with_media_includes_file(): void
    {
        [$product] = $this->seedMemberProduct();
        $path = 'products/'.$product->id.'.png';
        Storage::disk('public')->put($path, 'fake-image-bytes');
        $product->update(['image' => $path]);

        $storage = new StorageService(1);
        $export = new ProductExportService($storage);
        $result = $export->export($product->fresh(), true);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['path']) === true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertTrue($manifest['include_media']);
        $this->assertArrayHasKey($path, $manifest['media_map'] ?? []);
        $this->assertNotFalse($zip->getFromName($manifest['media_map'][$path]));
        $zip->close();
        @unlink($result['path']);
    }

    public function test_import_recreates_product_structure_without_media(): void
    {
        $admin = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_INFOPRODUTOR]);
        [$product] = $this->seedMemberProduct();

        $storage = new StorageService(1);
        $export = new ProductExportService($storage);
        $result = $export->export($product, false);

        $uploaded = new UploadedFile(
            $result['path'],
            $result['filename'],
            'application/zip',
            null,
            true
        );

        $response = $this->actingAs($admin)->post('/produtos/import', [
            'package' => $uploaded,
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $newId = $response->json('product_id');
        $this->assertNotNull($newId);
        $this->assertNotSame($product->id, $newId);

        $imported = Product::find($newId);
        $this->assertNotNull($imported);
        $this->assertStringContainsString('importado', (string) $imported->name);
        $this->assertSame('#112233', $imported->checkout_config['appearance']['primary_color'] ?? null);
        $this->assertSame(1, $imported->offers()->count());
        $this->assertSame(1, $imported->memberSections()->count());
        $this->assertSame(2, MemberModule::where('product_id', $imported->id)->count());
        $this->assertSame(1, MemberLesson::where('product_id', $imported->id)->count());

        $advanced = MemberModule::where('product_id', $imported->id)->where('title', 'Avançado')->first();
        $intro = MemberModule::where('product_id', $imported->id)->where('title', 'Intro')->first();
        $this->assertNotNull($advanced);
        $this->assertNotNull($intro);
        $this->assertSame([(int) $intro->id], array_map('intval', $advanced->release_required_module_ids ?? []));
    }

    public function test_import_with_media_rewrites_product_image(): void
    {
        $admin = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_INFOPRODUTOR]);
        [$product] = $this->seedMemberProduct();
        $path = 'products/cover-'.$product->id.'.png';
        Storage::disk('public')->put($path, 'png-bytes');
        $product->update(['image' => $path]);

        $storage = new StorageService(1);
        $export = new ProductExportService($storage);
        $result = $export->export($product->fresh(), true);

        $uploaded = new UploadedFile(
            $result['path'],
            $result['filename'],
            'application/zip',
            null,
            true
        );

        $response = $this->actingAs($admin)->post('/produtos/import', [
            'package' => $uploaded,
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $imported = Product::find($response->json('product_id'));
        $this->assertNotNull($imported);
        $this->assertNotEmpty($imported->image);
        $this->assertTrue(Storage::disk('public')->exists($imported->image));
        $this->assertSame('png-bytes', Storage::disk('public')->get($imported->image));
    }

    public function test_export_endpoint_downloads_zip(): void
    {
        $admin = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_INFOPRODUTOR]);
        [$product] = $this->seedMemberProduct();

        $response = $this->actingAs($admin)->post("/produtos/{$product->id}/export", [
            'include_media' => false,
        ]);

        $response->assertOk();
        $this->assertStringContainsString('zip', (string) $response->headers->get('content-type'));
    }
}
