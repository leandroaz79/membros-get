<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemberAreaPdfLocalStorageTest extends TestCase
{
    public function test_pdf_proxy_streams_from_local_storage_path_without_http(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        Storage::fake('public');

        $slug = 'pdfloc'.substr(uniqid('', true), -6);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
        ]);

        $storagePath = 'member-area/'.$product->id.'/local-test.pdf';
        Storage::disk('public')->put($storagePath, '%PDF-1.4 local-storage-test');

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo',
            'position' => 1,
        ]);

        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Leitor local',
            'position' => 1,
            'type' => MemberLesson::TYPE_PDF_READER,
            'content_url' => '',
            'content_files' => [
                ['url' => '/storage/'.$storagePath, 'name' => 'local-test.pdf'],
            ],
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        Http::fake();

        $res = $this->actingAs($aluno)->get('/m/'.$slug.'/aula/'.$lesson->id.'/pdf/0');

        $res->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('%PDF-1.4 local-storage-test', $res->getContent());
        Http::assertNothingSent();
    }

    public function test_pdf_proxy_streams_from_legacy_absolute_storage_url_with_foreign_host(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        Storage::fake('public');

        $slug = 'pdfleg'.substr(uniqid('', true), -6);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
        ]);

        $storagePath = 'member-pdf-library/1/legacy-host.pdf';
        Storage::disk('public')->put($storagePath, '%PDF-1.4 legacy-host-test');

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo',
            'position' => 1,
        ]);

        $legacyUrl = 'http://old-domain.test/storage/'.$storagePath;
        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Leitor legacy',
            'position' => 1,
            'type' => MemberLesson::TYPE_PDF_READER,
            'content_url' => $legacyUrl,
            'content_files' => [
                ['url' => $legacyUrl, 'name' => 'legacy-host.pdf'],
            ],
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        Http::fake();

        $res = $this->actingAs($aluno)->get('/m/'.$slug.'/aula/'.$lesson->id.'/pdf/0');

        $res->assertOk();
        $this->assertStringContainsString('%PDF-1.4 legacy-host-test', $res->getContent());
        Http::assertNothingSent();
    }
}
