<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberAreaLessonLayoutTest extends TestCase
{
    public function test_lesson_route_redirects_to_module_with_aula_query(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = 'lessonrd'.substr(uniqid('', true), -6);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Cursos',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo A',
            'position' => 1,
        ]);

        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula 1',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Olá</p>',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        $response = $this->actingAs($aluno)->get('/m/'.$slug.'/aula/'.$lesson->id);

        $response->assertRedirect('/m/'.$slug.'/modulo/'.$module->id.'?aula='.$lesson->id);
    }

    public function test_module_content_includes_lesson_navigation_and_module_thumbnail(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = 'lessonnav'.substr(uniqid('', true), -6);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Cursos',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo B',
            'position' => 1,
            'thumbnail' => 'member-area/thumbs/mod.jpg',
        ]);

        $lesson1 = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Primeira',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>1</p>',
        ]);

        $lesson2 = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Segunda',
            'position' => 2,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>2</p>',
        ]);

        $lesson3 = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Terceira',
            'position' => 3,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>3</p>',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        $response = $this->actingAs($aluno)->get('/m/'.$slug.'/modulo/'.$module->id.'?aula='.$lesson2->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('MemberAreaApp/ModuleContent')
            ->where('module.id', $module->id)
            ->where('module.thumbnail', fn ($url) => is_string($url) && str_contains($url, 'mod.jpg'))
            ->where('lesson_navigation.prev.id', $lesson1->id)
            ->where('lesson_navigation.prev.title', 'Primeira')
            ->where('lesson_navigation.next.id', $lesson3->id)
            ->where('lesson_navigation.next.title', 'Terceira')
            ->where('current_lesson.id', $lesson2->id)
        );
    }

    public function test_module_content_lesson_navigation_edges_are_null(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = 'lessonedge'.substr(uniqid('', true), -6);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Cursos',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo C',
            'position' => 1,
        ]);

        $first = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Início',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>1</p>',
        ]);

        MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Fim',
            'position' => 2,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>2</p>',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        $response = $this->actingAs($aluno)->get('/m/'.$slug.'/modulo/'.$module->id.'?aula='.$first->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('lesson_navigation.prev', null)
            ->where('lesson_navigation.next.id', fn ($id) => is_int($id))
        );
    }

    public function test_module_thumbnail_accepts_storage_prefixed_url(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = 'thumbst'.substr(uniqid('', true), -6);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Cursos',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $path = 'member-area/'.$product->id.'/cover.jpg';
        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Capa',
            'position' => 1,
            'thumbnail' => '/storage/'.$path,
        ]);

        MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>ok</p>',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        $response = $this->actingAs($aluno)->get('/m/'.$slug.'/modulo/'.$module->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('module.thumbnail', '/storage/'.$path)
        );
    }

    public function test_module_content_includes_next_module_on_last_lesson(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = 'nextmod'.substr(uniqid('', true), -6);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Cursos',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $moduleA = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo A',
            'position' => 1,
        ]);

        $moduleB = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo B',
            'position' => 2,
        ]);

        $lessonA = MemberLesson::create([
            'member_module_id' => $moduleA->id,
            'product_id' => $product->id,
            'title' => 'Única A',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>A</p>',
        ]);

        $lessonB = MemberLesson::create([
            'member_module_id' => $moduleB->id,
            'product_id' => $product->id,
            'title' => 'Primeira B',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>B</p>',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        $response = $this->actingAs($aluno)->get('/m/'.$slug.'/modulo/'.$moduleA->id.'?aula='.$lessonA->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('lesson_navigation.next', null)
            ->where('lesson_navigation.next_module.id', $moduleB->id)
            ->where('lesson_navigation.next_module.first_lesson_id', $lessonB->id)
            ->where('next_modules.0.id', $moduleB->id)
            ->where('next_modules.0.first_lesson.id', $lessonB->id)
        );
    }
}
