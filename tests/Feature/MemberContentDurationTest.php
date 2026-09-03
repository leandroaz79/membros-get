<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDockerSetup;
use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MemberContentDurationTest extends TestCase
{
    public function test_builder_saves_access_duration_for_modules_and_lessons(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, EnsureDockerSetup::class]);

        [$owner, $product, $section, $module] = $this->makeMemberContent();

        $this->actingAs($owner)->putJson(
            route('member-builder.modules.update', ['produto' => $product, 'module' => $module]),
            ['access_duration_days' => 180],
        )->assertOk();

        $lessonResponse = $this->actingAs($owner)->postJson(
            route('member-builder.lessons.store', ['produto' => $product, 'module' => $module]),
            [
                'title' => 'Aula temporária',
                'type' => MemberLesson::TYPE_TEXT,
                'content_text' => '<p>Conteúdo</p>',
                'access_duration_days' => 365,
            ],
        );

        $lessonResponse->assertOk();
        $this->assertDatabaseHas('member_modules', [
            'id' => $module->id,
            'access_duration_days' => 180,
        ]);
        $this->assertDatabaseHas('member_lessons', [
            'member_module_id' => $module->id,
            'title' => 'Aula temporária',
            'access_duration_days' => 365,
        ]);
    }

    public function test_expired_module_is_locked_and_cannot_be_opened(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, EnsureDockerSetup::class]);
        Carbon::setTestNow('2026-07-27 12:00:00');

        [, $product, , $module] = $this->makeMemberContent([
            'access_duration_days' => 30,
        ]);
        $lesson = $this->makeLesson($product, $module);
        $student = $this->attachStudent($product, now()->subDays(31));

        $this->actingAs($student)
            ->get('/m/'.$product->checkout_slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.modules.0.is_locked', true)
                ->where('sections.0.modules.0.lock_reason', 'expired')
                ->where('sections.0.modules.0.lock_message', 'Acesso encerrado em 26/07/2026')
            );

        $this->actingAs($student)
            ->get('/m/'.$product->checkout_slug.'/modulo/'.$module->id)
            ->assertRedirect('/m/'.$product->checkout_slug.'/modulos');

        $this->actingAs($student)
            ->postJson('/m/'.$product->checkout_slug.'/aula/'.$lesson->id.'/complete')
            ->assertForbidden();

        $this->assertDatabaseMissing('member_lesson_progress', [
            'member_lesson_id' => $lesson->id,
            'user_id' => $student->id,
        ]);
    }

    public function test_lesson_duration_expires_independently_from_its_module(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, EnsureDockerSetup::class]);
        Carbon::setTestNow('2026-07-27 12:00:00');

        [, $product, , $module] = $this->makeMemberContent();
        $expiredLesson = $this->makeLesson($product, $module, [
            'title' => 'Aula expirada',
            'position' => 1,
            'access_duration_days' => 30,
        ]);
        $availableLesson = $this->makeLesson($product, $module, [
            'title' => 'Aula disponível',
            'position' => 2,
        ]);
        $student = $this->attachStudent($product, now()->subDays(31));

        $this->actingAs($student)
            ->get('/m/'.$product->checkout_slug.'/modulo/'.$module->id.'?aula='.$expiredLesson->id)
            ->assertRedirect('/m/'.$product->checkout_slug.'/modulo/'.$module->id.'?aula='.$availableLesson->id);

        $this->actingAs($student)
            ->get('/m/'.$product->checkout_slug.'/modulo/'.$module->id.'?aula='.$availableLesson->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lessons.0.is_locked', true)
                ->where('lessons.0.lock_reason', 'expired')
                ->where('lessons.1.is_locked', false)
                ->where('current_lesson.id', $availableLesson->id)
            );
    }

    public function test_content_remains_available_until_duration_ends(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, EnsureDockerSetup::class]);
        Carbon::setTestNow('2026-07-27 12:00:00');

        [, $product, , $module] = $this->makeMemberContent([
            'access_duration_days' => 30,
        ]);
        $lesson = $this->makeLesson($product, $module);
        $student = $this->attachStudent($product, now()->subDays(29));

        $this->actingAs($student)
            ->get('/m/'.$product->checkout_slug.'/modulo/'.$module->id.'?aula='.$lesson->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('module.is_locked', false)
                ->where('module.expires_at', fn ($value) => is_string($value) && $value !== '')
                ->where('current_lesson.id', $lesson->id)
            );
    }

    /**
     * @param  array<string, mixed>  $moduleOverrides
     * @return array{User, Product, MemberSection, MemberModule}
     */
    private function makeMemberContent(array $moduleOverrides = []): array
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'duration'.substr(uniqid('', true), -8),
        ]);
        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Curso',
            'position' => 1,
            'section_type' => 'courses',
        ]);
        $module = MemberModule::create(array_merge([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo',
            'position' => 1,
        ], $moduleOverrides));

        return [$owner, $product, $section, $module];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeLesson(Product $product, MemberModule $module, array $overrides = []): MemberLesson
    {
        return MemberLesson::create(array_merge([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Aula',
            'position' => 1,
            'type' => MemberLesson::TYPE_TEXT,
            'content_text' => '<p>Conteúdo</p>',
        ], $overrides));
    }

    private function attachStudent(Product $product, Carbon $accessStartedAt): User
    {
        $student = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($student->id, [
            'created_at' => $accessStartedAt,
            'updated_at' => $accessStartedAt,
        ]);

        return $student;
    }
}
