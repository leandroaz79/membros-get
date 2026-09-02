<?php

namespace Tests\Feature;

use App\Models\MemberLesson;
use App\Models\MemberLessonProgress;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use App\Services\MemberProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleProgressReleaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{product: Product, section: MemberSection, mod1: MemberModule, mod2: MemberModule, l1a: MemberLesson, l1b: MemberLesson, l2a: MemberLesson, student: User, owner: User}
     */
    private function seedCourse(): array
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $owner->forceFill(['tenant_id' => $owner->id])->save();

        $product = $this->createTestProduct([
            'tenant_id' => $owner->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'mp'.substr(md5(uniqid('', true)), 0, 10),
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $mod1 = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo 1',
            'position' => 1,
        ]);
        $mod2 = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo 2',
            'position' => 2,
            'release_progress_percent' => 50,
        ]);

        $l1a = MemberLesson::create([
            'member_module_id' => $mod1->id,
            'product_id' => $product->id,
            'title' => 'A1',
            'type' => 'video',
            'position' => 1,
        ]);
        $l1b = MemberLesson::create([
            'member_module_id' => $mod1->id,
            'product_id' => $product->id,
            'title' => 'A2',
            'type' => 'video',
            'position' => 2,
        ]);
        $l2a = MemberLesson::create([
            'member_module_id' => $mod2->id,
            'product_id' => $product->id,
            'title' => 'B1',
            'type' => 'video',
            'position' => 1,
        ]);

        $student = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => $owner->id]);
        $product->users()->attach($student->id);

        return compact('product', 'section', 'mod1', 'mod2', 'l1a', 'l1b', 'l2a', 'student', 'owner');
    }

    public function test_is_module_completed_when_all_lessons_done(): void
    {
        $ctx = $this->seedCourse();
        $service = app(MemberProgressService::class);

        $this->assertFalse($service->isModuleCompleted($ctx['mod1'], $ctx['student']));

        MemberLessonProgress::create([
            'user_id' => $ctx['student']->id,
            'member_lesson_id' => $ctx['l1a']->id,
            'product_id' => $ctx['product']->id,
            'completed_at' => now(),
            'progress_percent' => 100,
        ]);
        $this->assertFalse($service->isModuleCompleted($ctx['mod1'], $ctx['student']));

        MemberLessonProgress::create([
            'user_id' => $ctx['student']->id,
            'member_lesson_id' => $ctx['l1b']->id,
            'product_id' => $ctx['product']->id,
            'completed_at' => now(),
            'progress_percent' => 100,
        ]);
        $this->assertTrue($service->isModuleCompleted($ctx['mod1'], $ctx['student']));
    }

    public function test_progress_percent_unlock_threshold(): void
    {
        $ctx = $this->seedCourse();
        $service = app(MemberProgressService::class);

        // 0/3 = 0% — below 50
        $this->assertSame(0, $service->completionPercent($ctx['product'], $ctx['student']));

        MemberLessonProgress::create([
            'user_id' => $ctx['student']->id,
            'member_lesson_id' => $ctx['l1a']->id,
            'product_id' => $ctx['product']->id,
            'completed_at' => now(),
            'progress_percent' => 100,
        ]);
        // 1/3 ≈ 33% still locked by threshold 50
        $this->assertSame(33, $service->completionPercent($ctx['product'], $ctx['student']));

        MemberLessonProgress::create([
            'user_id' => $ctx['student']->id,
            'member_lesson_id' => $ctx['l1b']->id,
            'product_id' => $ctx['product']->id,
            'completed_at' => now(),
            'progress_percent' => 100,
        ]);
        // 2/3 ≈ 67% — unlocks
        $this->assertSame(67, $service->completionPercent($ctx['product'], $ctx['student']));
        $this->assertGreaterThanOrEqual(50, $service->completionPercent($ctx['product'], $ctx['student']));
    }

    public function test_required_modules_all_must_complete(): void
    {
        $ctx = $this->seedCourse();
        $ctx['mod2']->update([
            'release_progress_percent' => null,
            'release_required_module_ids' => [$ctx['mod1']->id],
        ]);

        $service = app(MemberProgressService::class);

        $missing = $service->incompleteRequiredModuleTitles(
            [$ctx['mod1']->id],
            $ctx['product'],
            $ctx['student']
        );
        $this->assertSame(['Módulo 1'], $missing);

        MemberLessonProgress::create([
            'user_id' => $ctx['student']->id,
            'member_lesson_id' => $ctx['l1a']->id,
            'product_id' => $ctx['product']->id,
            'completed_at' => now(),
            'progress_percent' => 100,
        ]);
        MemberLessonProgress::create([
            'user_id' => $ctx['student']->id,
            'member_lesson_id' => $ctx['l1b']->id,
            'product_id' => $ctx['product']->id,
            'completed_at' => now(),
            'progress_percent' => 100,
        ]);

        $missing = $service->incompleteRequiredModuleTitles(
            [$ctx['mod1']->id],
            $ctx['product'],
            $ctx['student']
        );
        $this->assertSame([], $missing);
    }

    public function test_builder_rejects_self_as_prerequisite_by_filtering(): void
    {
        $ctx = $this->seedCourse();
        $this->actingAs($ctx['owner']);

        $response = $this->putJson(route('member-builder.modules.update', [
            'produto' => $ctx['product']->id,
            'module' => $ctx['mod2']->id,
        ]), [
            'title' => 'Módulo 2',
            'release_required_module_ids' => [$ctx['mod2']->id, $ctx['mod1']->id],
            'release_after_days' => null,
            'release_at_date' => null,
            'release_progress_percent' => null,
        ]);

        $response->assertOk();
        $ctx['mod2']->refresh();
        $this->assertSame([(int) $ctx['mod1']->id], array_map('intval', $ctx['mod2']->release_required_module_ids ?? []));
    }

    public function test_builder_rejects_foreign_product_module_id(): void
    {
        $ctx = $this->seedCourse();
        $otherOwner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $otherOwner->forceFill(['tenant_id' => $otherOwner->id])->save();
        $otherProduct = $this->createTestProduct([
            'tenant_id' => $otherOwner->id,
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'ox'.substr(md5(uniqid('', true)), 0, 10),
        ]);
        $otherSection = MemberSection::create([
            'product_id' => $otherProduct->id,
            'title' => 'Outra',
            'position' => 1,
            'section_type' => 'courses',
        ]);
        $foreignModule = MemberModule::create([
            'member_section_id' => $otherSection->id,
            'product_id' => $otherProduct->id,
            'title' => 'Estrangeiro',
            'position' => 1,
        ]);

        $this->actingAs($ctx['owner']);
        $response = $this->putJson(route('member-builder.modules.update', [
            'produto' => $ctx['product']->id,
            'module' => $ctx['mod2']->id,
        ]), [
            'title' => 'Módulo 2',
            'release_required_module_ids' => [$foreignModule->id],
            'release_after_days' => null,
            'release_at_date' => null,
            'release_progress_percent' => null,
        ]);

        $response->assertStatus(422);
    }
}
