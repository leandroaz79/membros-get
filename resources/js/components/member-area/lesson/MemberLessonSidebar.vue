<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { CheckCircle, Lock, Search, X, ChevronRight } from 'lucide-vue-next';

const props = defineProps({
    module: { type: Object, required: true },
    lessons: { type: Array, default: () => [] },
    currentLessonId: { type: Number, default: null },
    slug: { type: String, required: true },
    progressPercent: { type: Number, default: 0 },
    courseProgress: {
        type: Object,
        default: () => ({ completed: 0, total: 0 }),
    },
    isLessonCompleted: { type: Function, required: true },
    lessonUrl: { type: Function, required: true },
    moduleLessonUrl: { type: Function, default: null },
    nextModules: { type: Array, default: () => [] },
    mobile: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const query = ref('');
const thumbnailFailed = ref(false);

const filteredLessons = computed(() => {
    const q = query.value.trim().toLowerCase();
    const list = props.lessons || [];
    if (!q) return list;
    return list.filter((l) => (l.title || '').toLowerCase().includes(q));
});

const progressBarWidth = computed(() => {
    const { completed, total } = props.courseProgress;
    if (!total) return 0;
    return Math.min(100, Math.round((completed / total) * 100));
});

const moduleLessonProgress = computed(() => {
    const list = (props.lessons || []).filter((l) => !l.is_locked);
    const completed = list.filter((l) => props.isLessonCompleted(l)).length;
    return { completed, total: list.length };
});

const moduleProgressBarWidth = computed(() => {
    const { completed, total } = moduleLessonProgress.value;
    if (!total) return 0;
    return Math.min(100, Math.round((completed / total) * 100));
});

const moduleProgressLabel = computed(() => {
    const { completed, total } = moduleLessonProgress.value;
    if (!total) return '';
    if (completed === 0) return `Nenhuma de ${total} aulas concluída`;
    if (completed === 1) return `1 de ${total} aula concluída`;
    if (completed === total) return `Todas as ${total} aulas concluídas`;
    return `${completed} de ${total} aulas concluídas`;
});

const currentLesson = computed(() => {
    if (!props.currentLessonId) return null;
    return (props.lessons || []).find((l) => l.id === props.currentLessonId) ?? null;
});

const currentLessonIndex = computed(() => {
    if (!props.currentLessonId) return 0;
    const idx = (props.lessons || []).findIndex((l) => l.id === props.currentLessonId);
    return idx >= 0 ? idx + 1 : 0;
});

const showThumbnail = computed(() => Boolean(props.module.thumbnail) && !thumbnailFailed.value);

function nextModuleHref(mod) {
    if (typeof props.moduleLessonUrl === 'function' && mod?.first_lesson?.id) {
        return props.moduleLessonUrl(mod.id, mod.first_lesson.id);
    }
    return `/m/${props.slug}/modulo/${mod.id}`;
}
</script>

<template>
    <div
        class="flex h-full max-h-full min-h-0 flex-col overflow-hidden rounded-2xl shadow-xl shadow-black/20"
        :class="mobile ? 'max-h-[85vh] bg-zinc-950' : 'bg-zinc-950/60'"
    >
        <div class="relative shrink-0">
            <div
                class="w-full overflow-hidden bg-zinc-800"
                :class="mobile ? 'aspect-video' : 'h-28'"
            >
                <img
                    v-if="showThumbnail"
                    :src="module.thumbnail"
                    :alt="module.title"
                    class="h-full w-full object-cover"
                    @error="thumbnailFailed = true"
                />
                <div v-else class="flex h-full items-center justify-center">
                    <svg class="h-12 w-12 text-zinc-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                </div>
                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/90 via-black/50 to-transparent px-4 pb-4 pt-12">
                    <p v-if="module.section" class="text-xs font-medium uppercase tracking-wide text-white/60">{{ module.section.title }}</p>
                    <h2 class="mt-0.5 text-lg font-semibold leading-tight text-white">{{ module.title }}</h2>
                </div>
            </div>
            <button
                v-if="mobile"
                type="button"
                class="absolute right-3 top-3 rounded-full bg-black/60 p-2 text-white transition hover:bg-black/80"
                aria-label="Fechar lista de aulas"
                @click="emit('close')"
            >
                <X class="h-4 w-4" />
            </button>
        </div>

        <div class="shrink-0 space-y-3 px-4 py-4">
            <div class="space-y-1.5">
                <div class="flex items-center justify-between text-xs text-zinc-400">
                    <span>{{ progressPercent }}% do curso</span>
                    <span v-if="courseProgress.total > 0" class="tabular-nums">
                        {{ courseProgress.completed }}/{{ courseProgress.total }} aulas
                    </span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-zinc-700/80">
                    <div
                        class="h-full rounded-full bg-[var(--ma-primary)] transition-[width]"
                        :style="{ width: `${progressBarWidth}%` }"
                    />
                </div>
            </div>
            <div class="relative">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                <input
                    v-model="query"
                    type="search"
                    placeholder="Busque por uma aula…"
                    class="w-full rounded-xl bg-white/5 py-2.5 pl-9 pr-3 text-sm text-white placeholder-zinc-500 focus:bg-white/10 focus:outline-none"
                    autocomplete="off"
                />
            </div>
        </div>

        <div
            v-if="moduleLessonProgress.total > 0"
            class="mx-3 mb-2 shrink-0 rounded-xl bg-[var(--ma-primary)]/10 px-4 py-3"
        >
            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--ma-primary)]">
                Progresso deste módulo
            </p>
            <p class="mt-1 text-sm font-medium text-white">
                {{ moduleProgressLabel }}
            </p>
            <div class="mt-2 h-1 overflow-hidden rounded-full bg-zinc-700/80">
                <div
                    class="h-full rounded-full bg-[var(--ma-primary)] transition-[width]"
                    :style="{ width: `${moduleProgressBarWidth}%` }"
                />
            </div>
            <p v-if="currentLesson" class="mt-2.5 truncate text-xs text-zinc-400">
                Aula {{ currentLessonIndex }} ·
                <span class="font-medium text-zinc-200">{{ currentLesson.title || 'Sem título' }}</span>
            </p>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 pb-4">
            <nav class="pb-2">
            <template v-if="filteredLessons.length">
                <template v-for="(lesson, idx) in filteredLessons" :key="lesson.id">
                    <Link
                        v-if="!lesson.is_locked"
                        :href="lessonUrl(lesson.id)"
                        class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition"
                        :class="currentLessonId === lesson.id
                            ? 'bg-[var(--ma-primary)] text-white shadow-lg shadow-[var(--ma-primary)]/20'
                            : 'text-zinc-300 hover:bg-white/5 hover:text-white'"
                        @click="mobile && emit('close')"
                    >
                        <CheckCircle
                            v-if="isLessonCompleted(lesson)"
                            class="h-4 w-4 shrink-0"
                            :class="currentLessonId === lesson.id ? 'text-white' : 'text-emerald-500'"
                        />
                        <span
                            v-else
                            class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold"
                            :class="currentLessonId === lesson.id
                                ? 'bg-white/20 text-white'
                                : 'bg-white/10 text-zinc-400 group-hover:bg-white/15'"
                        >
                            {{ idx + 1 }}
                        </span>
                        <span class="min-w-0 flex-1 truncate font-medium">{{ lesson.title || 'Sem título' }}</span>
                    </Link>
                    <div
                        v-else
                        class="flex cursor-not-allowed items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm opacity-60"
                    >
                        <Lock class="h-4 w-4 shrink-0 text-zinc-500" />
                        <span class="min-w-0 flex-1 truncate text-zinc-400">{{ lesson.title || 'Sem título' }}</span>
                        <span v-if="lesson.lock_message" class="shrink-0 text-[10px] text-zinc-500">{{ lesson.lock_message }}</span>
                    </div>
                </template>
            </template>
            <p v-else-if="lessons.length" class="px-3 py-6 text-center text-sm text-zinc-500">Nenhuma aula encontrada.</p>
            <p v-else class="px-3 py-6 text-center text-sm text-zinc-500">Nenhuma aula neste módulo.</p>
            </nav>

            <div
                v-if="nextModules.length"
                class="border-t border-white/5 pt-3"
            >
                <p class="px-2 pb-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">
                    Próximos módulos
                </p>
                <div class="space-y-1">
                    <Link
                        v-for="mod in nextModules"
                        :key="mod.id"
                        :href="nextModuleHref(mod)"
                        class="group flex items-center gap-2.5 rounded-xl px-2 py-2 transition hover:bg-white/5"
                        @click="mobile && emit('close')"
                    >
                        <div class="relative h-11 w-16 shrink-0 overflow-hidden rounded-lg bg-zinc-800">
                            <img
                                v-if="mod.thumbnail"
                                :src="mod.thumbnail"
                                :alt="mod.title"
                                class="h-full w-full object-cover transition group-hover:scale-[1.03]"
                            />
                            <div v-else class="flex h-full items-center justify-center">
                                <ChevronRight class="h-4 w-4 text-zinc-600" />
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p v-if="mod.section_title" class="truncate text-[10px] font-medium uppercase tracking-wide text-zinc-500">
                                {{ mod.section_title }}
                            </p>
                            <p class="truncate text-sm font-medium text-zinc-200 group-hover:text-white">
                                {{ mod.title }}
                            </p>
                            <p v-if="mod.first_lesson?.title" class="truncate text-[11px] text-zinc-500">
                                {{ mod.first_lesson.title }}
                            </p>
                        </div>
                        <ChevronRight class="h-4 w-4 shrink-0 text-zinc-600 transition group-hover:text-[var(--ma-primary)]" />
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
