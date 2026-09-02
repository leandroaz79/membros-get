<?php

namespace App\Services;

use App\Models\MemberCertificateIssued;
use App\Models\MemberLesson;
use App\Models\MemberLessonProgress;
use App\Models\MemberModule;
use App\Models\Product;
use App\Models\User;

class MemberProgressService
{
    /**
     * IDs de aulas que contam para o produto host: nativas + aulas de módulos embutidos (outra área).
     *
     * @return array<int, int|string>
     */
    public function lessonIdsForMemberAreaHost(Product $product): array
    {
        $nativeIds = MemberLesson::where('product_id', $product->id)->pluck('id')->all();
        $sourceModuleIds = MemberModule::where('product_id', $product->id)
            ->whereNotNull('source_member_module_id')
            ->pluck('source_member_module_id')
            ->unique()
            ->filter()
            ->values()
            ->all();
        if ($sourceModuleIds === []) {
            return array_values(array_unique($nativeIds));
        }
        $embedIds = MemberLesson::whereIn('member_module_id', $sourceModuleIds)->pluck('id')->all();

        return array_values(array_unique(array_merge($nativeIds, $embedIds)));
    }

    /**
     * Total lessons count for product (for completion %).
     */
    public function totalLessonsCount(Product $product): int
    {
        return count($this->lessonIdsForMemberAreaHost($product));
    }

    /**
     * Completed lessons count for user in product.
     */
    public function completedLessonsCount(Product $product, User $user): int
    {
        return count($this->completedLessonIdsForProduct($product, $user));
    }

    /**
     * IDs de aulas concluídas pelo usuário no produto (host + embutidas).
     *
     * @return array<int, int|string>
     */
    public function completedLessonIdsForProduct(Product $product, User $user): array
    {
        $ids = $this->lessonIdsForMemberAreaHost($product);
        if ($ids === []) {
            return [];
        }

        return MemberLessonProgress::query()
            ->forUser($user->id)
            ->whereNotNull('completed_at')
            ->whereIn('member_lesson_id', $ids)
            ->pluck('member_lesson_id')
            ->all();
    }

    /**
     * Set lookup: lesson_id => true (evita N+1 ao montar listagens da área de membros).
     *
     * @return array<int|string, true>
     */
    public function completedLessonIdSet(Product $product, User $user): array
    {
        $set = [];
        foreach ($this->completedLessonIdsForProduct($product, $user) as $lessonId) {
            $set[$lessonId] = true;
        }

        return $set;
    }

    /**
     * Completion percentage (0-100).
     */
    public function completionPercent(Product $product, User $user): int
    {
        $total = $this->totalLessonsCount($product);
        if ($total === 0) {
            return 100;
        }
        $completed = $this->completedLessonsCount($product, $user);

        return (int) min(100, round(($completed / $total) * 100));
    }

    /**
     * Aulas do módulo de conteúdo (resolve wrapper embutido → source).
     *
     * @return \Illuminate\Support\Collection<int, MemberLesson>
     */
    public function contentLessonsForModule(MemberModule $module)
    {
        $content = $module;
        if ($module->source_member_module_id) {
            $source = $module->relationLoaded('sourceModule')
                ? $module->sourceModule
                : MemberModule::query()->with('lessons')->find($module->source_member_module_id);
            if ($source) {
                $content = $source;
            }
        }

        if ($content->relationLoaded('lessons')) {
            return $content->lessons;
        }

        return $content->lessons()->orderBy('position')->get();
    }

    /**
     * @param  array<int|string, true>|null  $completedIdSet
     */
    public function isModuleCompleted(MemberModule $module, User $user, ?array $completedIdSet = null): bool
    {
        $lessons = $this->contentLessonsForModule($module);
        if ($lessons->isEmpty()) {
            return true;
        }

        if ($completedIdSet === null) {
            $lessonIds = $lessons->pluck('id')->all();
            $done = MemberLessonProgress::query()
                ->forUser($user->id)
                ->whereNotNull('completed_at')
                ->whereIn('member_lesson_id', $lessonIds)
                ->pluck('member_lesson_id')
                ->all();
            $completedIdSet = [];
            foreach ($done as $id) {
                $completedIdSet[$id] = true;
            }
        }

        foreach ($lessons as $lesson) {
            if (! isset($completedIdSet[$lesson->id])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int|string, true>|null  $completedIdSet
     */
    public function moduleCompletionPercent(MemberModule $module, User $user, ?array $completedIdSet = null): int
    {
        $lessons = $this->contentLessonsForModule($module);
        $total = $lessons->count();
        if ($total === 0) {
            return 100;
        }

        if ($completedIdSet === null) {
            $lessonIds = $lessons->pluck('id')->all();
            $doneCount = MemberLessonProgress::query()
                ->forUser($user->id)
                ->whereNotNull('completed_at')
                ->whereIn('member_lesson_id', $lessonIds)
                ->count();

            return (int) min(100, round(($doneCount / $total) * 100));
        }

        $done = 0;
        foreach ($lessons as $lesson) {
            if (isset($completedIdSet[$lesson->id])) {
                $done++;
            }
        }

        return (int) min(100, round(($done / $total) * 100));
    }

    /**
     * Títulos dos módulos obrigatórios ainda incompletos (mesma área / product_id do host).
     *
     * @param  list<int|string>  $requiredModuleIds
     * @param  array<int|string, true>|null  $completedIdSet
     * @return list<string>
     */
    public function incompleteRequiredModuleTitles(
        array $requiredModuleIds,
        Product $hostProduct,
        User $user,
        ?array $completedIdSet = null
    ): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $requiredModuleIds))));
        if ($ids === []) {
            return [];
        }

        $modules = MemberModule::query()
            ->where('product_id', $hostProduct->id)
            ->whereIn('id', $ids)
            ->with(['lessons', 'sourceModule.lessons'])
            ->get()
            ->keyBy('id');

        if ($completedIdSet === null) {
            $completedIdSet = $this->completedLessonIdSet($hostProduct, $user);
        }

        $missing = [];
        foreach ($ids as $id) {
            $mod = $modules->get($id);
            if (! $mod) {
                continue;
            }
            if (! $this->isModuleCompleted($mod, $user, $completedIdSet)) {
                $missing[] = (string) $mod->title;
            }
        }

        return $missing;
    }

    /**
     * Most recent in-progress lesson for "continue watching" (member area host product).
     *
     * @return array{lesson_id: int|string, module_id: int|string|null, lesson_title: string, module_title: string|null}|null
     */
    public function latestContinueWatching(Product $product, User $user): ?array
    {
        $lessonIds = $this->lessonIdsForMemberAreaHost($product);
        if ($lessonIds === []) {
            return null;
        }

        $progress = MemberLessonProgress::query()
            ->forUser($user->id)
            ->whereNull('completed_at')
            ->whereIn('member_lesson_id', $lessonIds)
            ->with('lesson.module')
            ->latest('updated_at')
            ->first();

        if (! $progress?->lesson) {
            return null;
        }

        $lesson = $progress->lesson;
        $wrapper = MemberModule::query()
            ->where('product_id', $product->id)
            ->where('source_member_module_id', $lesson->member_module_id)
            ->first();

        $moduleForMeta = $wrapper ?? $lesson->module;

        return [
            'lesson_id' => $lesson->id,
            'module_id' => $wrapper?->id ?? $lesson->module?->id,
            'lesson_title' => $lesson->title,
            'module_title' => $moduleForMeta?->title,
        ];
    }

    /**
     * Garante que existe um registro de progresso ao abrir a aula (para "continuar assistindo").
     * Só cria se não existir; não altera aulas já concluídas.
     */
    public function ensureLessonStarted(MemberLesson $lesson, User $user): void
    {
        MemberLessonProgress::firstOrCreate(
            [
                'user_id' => $user->id,
                'member_lesson_id' => $lesson->id,
            ],
            [
                'product_id' => $lesson->product_id,
                'completed_at' => null,
            ]
        );
    }

    /**
     * Mark lesson as completed for user.
     */
    public function markLessonCompleted(int $lessonId, User $user): void
    {
        $lesson = MemberLesson::find($lessonId);
        if (! $lesson) {
            return;
        }
        MemberLessonProgress::updateOrCreate(
            [
                'user_id' => $user->id,
                'member_lesson_id' => $lessonId,
            ],
            [
                'product_id' => $lesson->product_id,
                'completed_at' => now(),
                'progress_percent' => 100,
            ]
        );
    }

    /**
     * Check if user can receive certificate (completion >= config and not already issued).
     */
    public function canIssueCertificate(Product $product, User $user): bool
    {
        $config = $product->member_area_config;
        $cert = $config['certificate'] ?? [];
        if (empty($cert['enabled'])) {
            return false;
        }
        $requiredPercent = (int) ($cert['completion_percent'] ?? 100);
        $percent = $this->completionPercent($product, $user);
        if ($percent < $requiredPercent) {
            return false;
        }
        return ! MemberCertificateIssued::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->exists();
    }

    /**
     * Issue certificate for user/product (if allowed).
     */
    public function issueCertificate(Product $product, User $user): ?MemberCertificateIssued
    {
        if (! $this->canIssueCertificate($product, $user)) {
            return null;
        }
        $percent = $this->completionPercent($product, $user);
        return MemberCertificateIssued::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'issued_at' => now(),
            'completion_percent' => $percent,
        ]);
    }
}
