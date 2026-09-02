<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberBuilderLessonSupportAndLinksTest extends TestCase
{
    private function createCourseModule(User $owner): array
    {
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'supp'.substr(uniqid('', true), -8),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo',
            'position' => 1,
        ]);

        return [$product, $module];
    }

    public function test_store_video_lesson_persists_support_files_and_useful_links(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        [$product, $module] = $this->createCourseModule($owner);

        $pdfUrl = 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf';
        $linkUrl = 'https://example.com/recurso';

        $response = $this->actingAs($owner)->postJson(
            route('member-builder.lessons.store', ['produto' => $product, 'module' => $module]),
            [
                'title' => 'Aula com extras',
                'type' => MemberLesson::TYPE_VIDEO,
                'content_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'content_files' => [],
                'support_files' => [
                    ['url' => $pdfUrl, 'name' => 'Apostila.pdf'],
                ],
                'useful_links' => [
                    ['title' => 'Documentação', 'url' => $linkUrl],
                ],
                'link_title' => '',
                'release_after_days' => null,
                'release_at_date' => null,
                'content_text' => '',
                'duration_seconds' => 0,
                'is_free' => false,
                'watermark_enabled' => false,
            ]
        );

        $response->assertOk();
        $lesson = MemberLesson::where('member_module_id', $module->id)->first();
        $this->assertNotNull($lesson);
        $this->assertSame(MemberLesson::TYPE_VIDEO, $lesson->type);
        $this->assertNull($lesson->content_files);
        $this->assertIsArray($lesson->support_files);
        $this->assertSame($pdfUrl, $lesson->support_files[0]['url'] ?? null);
        $this->assertSame('Apostila.pdf', $lesson->support_files[0]['name'] ?? null);
        $this->assertIsArray($lesson->useful_links);
        $this->assertSame('Documentação', $lesson->useful_links[0]['title'] ?? null);
        $this->assertSame($linkUrl, $lesson->useful_links[0]['url'] ?? null);
    }

    public function test_update_lesson_clears_support_files_and_useful_links_when_empty(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        [$product, $module] = $this->createCourseModule($owner);

        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula',
            'position' => 1,
            'type' => MemberLesson::TYPE_VIDEO,
            'content_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'support_files' => [
                ['url' => 'https://example.com/file.pdf', 'name' => 'file.pdf'],
            ],
            'useful_links' => [
                ['title' => 'Link', 'url' => 'https://example.com'],
            ],
        ]);

        $response = $this->actingAs($owner)->putJson(
            route('member-builder.lessons.update', ['produto' => $product, 'lesson' => $lesson]),
            [
                'title' => 'Aula',
                'type' => MemberLesson::TYPE_VIDEO,
                'content_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'content_files' => [],
                'support_files' => [],
                'useful_links' => [],
                'link_title' => '',
                'release_after_days' => null,
                'release_at_date' => null,
                'content_text' => '',
                'duration_seconds' => 0,
                'is_free' => false,
                'watermark_enabled' => false,
            ]
        );

        $response->assertOk();
        $lesson->refresh();
        $this->assertNull($lesson->support_files);
        $this->assertNull($lesson->useful_links);
    }
}
