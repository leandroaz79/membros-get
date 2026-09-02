<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberBuilderDuplicateTest extends TestCase
{
    public function test_duplicate_section_creates_copy_with_modules_and_lessons(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'dupsec'.substr(uniqid('', true), -8),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção original',
            'position' => 1,
            'cover_mode' => 'vertical',
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo A',
            'position' => 1,
        ]);

        MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 1',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Conteúdo</p>',
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('member-builder.sections.duplicate', ['produto' => $product, 'section' => $section])
        );

        $response->assertOk();
        $response->assertJsonPath('section.title', 'Seção original (cópia)');

        $this->assertSame(2, MemberSection::where('product_id', $product->id)->count());
        $cloneId = $response->json('section.id');
        $this->assertNotSame($section->id, $cloneId);

        $cloneModules = MemberModule::where('member_section_id', $cloneId)->get();
        $this->assertCount(1, $cloneModules);
        $this->assertSame('Módulo A (cópia)', $cloneModules->first()->title);
        $this->assertSame(1, MemberLesson::where('member_module_id', $cloneModules->first()->id)->count());
    }

    public function test_duplicate_lesson_inserts_copy_after_original(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'dupless'.substr(uniqid('', true), -8),
        ]);

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
            'title' => 'Aula original',
            'position' => 1,
            'type' => MemberLesson::TYPE_VIDEO,
            'content_url' => 'https://example.com/video.mp4',
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('member-builder.lessons.duplicate', ['produto' => $product, 'lesson' => $lesson])
        );

        $response->assertOk();
        $response->assertJsonPath('lesson.title', 'Aula original (cópia)');

        $lessons = MemberLesson::where('member_module_id', $module->id)->orderBy('position')->get();
        $this->assertCount(2, $lessons);
        $this->assertSame($lesson->id, $lessons[0]->id);
        $this->assertSame('Aula original (cópia)', $lessons[1]->title);
        $this->assertSame(2, $lessons[1]->position);
    }
}
