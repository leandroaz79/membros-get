<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import MemberAreaAppLayout from '@/Layouts/MemberAreaAppLayout.vue';
import MemberLessonSidebar from '@/components/member-area/lesson/MemberLessonSidebar.vue';
import MemberLessonContent from '@/components/member-area/lesson/MemberLessonContent.vue';
import MemberLessonToolbar from '@/components/member-area/lesson/MemberLessonToolbar.vue';
import MemberLessonMaterials from '@/components/member-area/lesson/MemberLessonMaterials.vue';
import MemberLessonComments from '@/components/member-area/lesson/MemberLessonComments.vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { useMemberAreaCinemaMode } from '@/composables/useMemberAreaCinemaMode.js';

defineOptions({ layout: MemberAreaAppLayout });

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    slug: { type: String, required: true },
    module: { type: Object, required: true },
    lessons: { type: Array, default: () => [] },
    current_lesson: { type: Object, default: null },
    lesson_navigation: {
        type: Object,
        default: () => ({ prev: null, next: null, next_module: null }),
    },
    next_modules: { type: Array, default: () => [] },
    progress_percent: { type: Number, default: 0 },
    sections: { type: Array, default: () => [] },
    comments_enabled: { type: Boolean, default: false },
    comments_require_approval: { type: Boolean, default: true },
    lesson_comments: { type: Array, default: () => [] },
    base_url: { type: String, default: '' },
    course_lesson_progress: {
        type: Object,
        default: () => ({ completed: 0, total: 0 }),
    },
});

const memberAreaBaseUrl = computed(() => {
    const u = (props.base_url || '').trim();
    if (u) return u.replace(/\/$/, '');
    return `/m/${props.slug}`;
});

const courseProgress = computed(() => props.course_lesson_progress || { completed: 0, total: 0 });

const completedLessonIds = ref(new Set());
const completed = ref(props.current_lesson?.is_completed ?? false);
const completing = ref(false);
const { cinemaMode, setCinemaMode, toggleCinemaMode } = useMemberAreaCinemaMode();
const mobileSidebarOpen = ref(false);
const NEXT_COUNTDOWN_SECONDS = 5;
const NEXT_OVERLAY_BEFORE_END_SECONDS = 2;
const AUTOPLAY_STORAGE_KEY = 'ma-lesson-autoplay';
const VIDEO_AUTO_COMPLETE_PERCENT = 60;

const nextOverlayVisible = ref(false);
const nextCountdown = ref(NEXT_COUNTDOWN_SECONDS);
const nextLessonTarget = ref(null);
const shouldAutoplayVideo = ref(false);
let autoCompleteTimer = null;
let nextCountdownTimer = null;
let nextOverlayTriggered = false;

const isLessonCompletedFn = (lesson) => lesson.is_completed || completedLessonIds.value.has(lesson.id);

function lessonUrl(lessonId) {
    return `/m/${props.slug}/modulo/${props.module.id}?aula=${lessonId}`;
}

function moduleLessonUrl(moduleId, lessonId) {
    return `/m/${props.slug}/modulo/${moduleId}?aula=${lessonId}`;
}

const nextAutoTarget = computed(() => {
    const nav = props.lesson_navigation || {};
    if (nav.next?.id) {
        return {
            kind: 'lesson',
            url: lessonUrl(nav.next.id),
            title: nav.next.title,
        };
    }
    const nm = nav.next_module;
    if (nm?.id && nm?.first_lesson_id) {
        return {
            kind: 'module',
            url: moduleLessonUrl(nm.id, nm.first_lesson_id),
            title: nm.title,
            subtitle: nm.first_lesson_title,
        };
    }
    return null;
});

function markComplete() {
    return new Promise((resolve) => {
        if (!props.current_lesson || completed.value || completing.value) {
            resolve();
            return;
        }
        completing.value = true;
        router.post(`/m/${props.slug}/aula/${props.current_lesson.id}/complete`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                completed.value = true;
                completedLessonIds.value.add(props.current_lesson.id);
            },
            onFinish: () => {
                completing.value = false;
                resolve();
            },
        });
    });
}

function clearNextCountdown() {
    if (nextCountdownTimer) {
        clearInterval(nextCountdownTimer);
        nextCountdownTimer = null;
    }
    nextOverlayVisible.value = false;
    nextLessonTarget.value = null;
    nextCountdown.value = NEXT_COUNTDOWN_SECONDS;
}

function consumeAutoplayIntent() {
    const id = props.current_lesson?.id;
    shouldAutoplayVideo.value = false;
    if (!id) return;
    try {
        const stored = sessionStorage.getItem(AUTOPLAY_STORAGE_KEY);
        if (stored && String(id) === stored) {
            shouldAutoplayVideo.value = true;
            sessionStorage.removeItem(AUTOPLAY_STORAGE_KEY);
        }
    } catch (_) {}
}

function goToNextLesson() {
    const target = nextLessonTarget.value;
    if (!target?.url) return;
    clearNextCountdown();
    try {
        if (target.lessonId) {
            sessionStorage.setItem(AUTOPLAY_STORAGE_KEY, String(target.lessonId));
        }
    } catch (_) {}
    router.visit(target.url);
}

function startNextCountdown(target) {
    if (nextOverlayTriggered || !target?.url) return;
    nextOverlayTriggered = true;
    if (nextCountdownTimer) {
        clearInterval(nextCountdownTimer);
        nextCountdownTimer = null;
    }
    nextLessonTarget.value = target;
    nextCountdown.value = NEXT_COUNTDOWN_SECONDS;
    nextOverlayVisible.value = true;
    nextCountdownTimer = setInterval(() => {
        nextCountdown.value -= 1;
        if (nextCountdown.value <= 0) {
            goToNextLesson();
        }
    }, 1000);
}

function onCancelNext() {
    nextOverlayTriggered = true;
    clearNextCountdown();
}

function maybeShowNextOverlay() {
    const target = nextAutoTarget.value;
    if (!target) return;
    if (!completed.value && !completing.value) {
        markComplete();
    }
    startNextCountdown({
        kind: target.kind,
        url: target.url,
        title: target.title,
        subtitle: target.subtitle,
        lessonId: target.kind === 'lesson'
            ? props.lesson_navigation?.next?.id
            : props.lesson_navigation?.next_module?.first_lesson_id,
    });
}

async function onVideoEnded() {
    await markComplete();
    if (!nextOverlayTriggered && nextAutoTarget.value) {
        const t = nextAutoTarget.value;
        startNextCountdown({
            kind: t.kind,
            url: t.url,
            title: t.title,
            subtitle: t.subtitle,
            lessonId: t.kind === 'lesson'
                ? props.lesson_navigation?.next?.id
                : props.lesson_navigation?.next_module?.first_lesson_id,
        });
    }
}

function onVideoProgress({ percent, currentTime, duration }) {
    if (!completed.value && !completing.value && percent >= VIDEO_AUTO_COMPLETE_PERCENT) {
        markComplete();
    }
    if (nextOverlayTriggered || !nextAutoTarget.value) return;
    if (!Number.isFinite(duration) || duration <= 0 || !Number.isFinite(currentTime)) return;
    const remaining = duration - currentTime;
    if (remaining <= NEXT_OVERLAY_BEFORE_END_SECONDS) {
        maybeShowNextOverlay();
    }
}

function shouldAutoCompleteNonVideo() {
    if (!props.current_lesson || completed.value) return false;
    const t = props.current_lesson.type;
    if (t === 'pdf_presentation' || t === 'pdf_reader') return false;
    return t === 'link' || t === 'pdf' || t === 'text' || (t !== 'video' && (props.current_lesson.content_url || props.current_lesson.content_text));
}

function resetLessonState() {
    if (autoCompleteTimer) {
        clearTimeout(autoCompleteTimer);
        autoCompleteTimer = null;
    }
    clearNextCountdown();
    nextOverlayTriggered = false;
    consumeAutoplayIntent();
    completed.value = props.current_lesson?.is_completed ?? false;
    mobileSidebarOpen.value = false;
    if (props.current_lesson?.is_completed) {
        completedLessonIds.value.add(props.current_lesson.id);
    } else if (shouldAutoCompleteNonVideo()) {
        autoCompleteTimer = setTimeout(() => markComplete(), 500);
    }
}

onMounted(resetLessonState);

watch(() => props.current_lesson?.id, resetLessonState);

onUnmounted(() => {
    if (autoCompleteTimer) clearTimeout(autoCompleteTimer);
    clearNextCountdown();
    setCinemaMode(false);
});

function onKeydown(e) {
    if (e.key === 'Escape' && cinemaMode.value) {
        setCinemaMode(false);
    }
}

const LG_BREAKPOINT = 1024;

function syncCinemaModeForViewport() {
    if (typeof window !== 'undefined' && window.innerWidth < LG_BREAKPOINT && cinemaMode.value) {
        setCinemaMode(false);
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    syncCinemaModeForViewport();
    window.addEventListener('resize', syncCinemaModeForViewport);
});
onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('resize', syncCinemaModeForViewport);
});

const commentSubmitting = ref(false);
function submitComment(content) {
    if (!props.current_lesson || !props.comments_enabled || !content) return;
    commentSubmitting.value = true;
    router.post(`/m/${props.slug}/aula/${props.current_lesson.id}/comments`, { content }, {
        preserveScroll: true,
        onFinish: () => { commentSubmitting.value = false; },
    });
}

const carouselRefs = ref({});
const carouselHasOverflow = ref({});

function checkCarouselOverflow(sectionId) {
    const el = carouselRefs.value[sectionId];
    if (!el || typeof el.scrollWidth !== 'number') return;
    const hasOverflow = el.scrollWidth > el.clientWidth;
    if (carouselHasOverflow.value[sectionId] === hasOverflow) return;
    carouselHasOverflow.value = { ...carouselHasOverflow.value, [sectionId]: hasOverflow };
}

function setCarouselRef(sectionId, el) {
    if (el) {
        carouselRefs.value[sectionId] = el;
        setTimeout(() => checkCarouselOverflow(sectionId), 0);
    } else {
        carouselRefs.value[sectionId] = null;
        if (carouselHasOverflow.value[sectionId] !== false) {
            carouselHasOverflow.value = { ...carouselHasOverflow.value, [sectionId]: false };
        }
    }
}

function scrollCarousel(sectionId, direction) {
    const el = carouselRefs.value[sectionId];
    if (!el) return;
    el.scrollBy({ left: 272 * direction, behavior: 'smooth' });
}
</script>

<template>
    <div class="space-y-8" :class="cinemaMode && current_lesson ? 'space-y-2' : ''">
        <div
            class="relative"
            :class="cinemaMode && current_lesson ? '' : ''"
        >
            <template v-if="current_lesson && cinemaMode">
                <main class="relative z-0 min-w-0 space-y-2">
                    <div class="transition-all duration-300 ease-out">
                        <MemberLessonContent
                            :lesson="current_lesson"
                            :member-area-base-url="memberAreaBaseUrl"
                            :cinema-mode="cinemaMode"
                            :autoplay-video="shouldAutoplayVideo"
                            :next-overlay-visible="nextOverlayVisible"
                            :next-target="nextLessonTarget"
                            :next-countdown="nextCountdown"
                            :next-countdown-total="NEXT_COUNTDOWN_SECONDS"
                            @ended="onVideoEnded"
                            @progress="onVideoProgress"
                            @last-page-reached="markComplete"
                            @play-next="goToNextLesson"
                            @cancel-next="onCancelNext"
                        />
                    </div>
                    <MemberLessonToolbar
                        :title="current_lesson.title"
                        :slug="slug"
                        :lesson-id="current_lesson.id"
                        :base-url="memberAreaBaseUrl"
                        :cinema-mode="cinemaMode"
                        :completed="completed"
                        :completing="completing"
                        :likes-count="current_lesson.likes_count ?? 0"
                        :user-liked="!!current_lesson.user_liked"
                        :user-note="current_lesson.user_note ?? ''"
                        :navigation="lesson_navigation"
                        :lesson-url="lessonUrl"
                        :module-lesson-url="moduleLessonUrl"
                        show-lessons-button
                        @toggle-cinema="toggleCinemaMode()"
                        @complete="markComplete"
                        @open-lessons="mobileSidebarOpen = true"
                    />
                </main>
            </template>

            <template v-else-if="current_lesson">
                <div class="lesson-stage grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] xl:grid-cols-[minmax(0,1fr)_26rem] lg:items-start lg:gap-5 lg:-mr-6">
                    <div class="lesson-main min-w-0 space-y-5 lg:pr-1">
                        <div class="lesson-video-shell transition-all duration-300 ease-out">
                            <MemberLessonContent
                                :lesson="current_lesson"
                                :member-area-base-url="memberAreaBaseUrl"
                                :cinema-mode="cinemaMode"
                                :autoplay-video="shouldAutoplayVideo"
                                :next-overlay-visible="nextOverlayVisible"
                                :next-target="nextLessonTarget"
                                :next-countdown="nextCountdown"
                                :next-countdown-total="NEXT_COUNTDOWN_SECONDS"
                                @ended="onVideoEnded"
                                @progress="onVideoProgress"
                                @last-page-reached="markComplete"
                                @play-next="goToNextLesson"
                                @cancel-next="onCancelNext"
                            />
                        </div>

                        <MemberLessonToolbar
                            :title="current_lesson.title"
                            :slug="slug"
                            :lesson-id="current_lesson.id"
                            :base-url="memberAreaBaseUrl"
                            :cinema-mode="cinemaMode"
                            :completed="completed"
                            :completing="completing"
                            :likes-count="current_lesson.likes_count ?? 0"
                            :user-liked="!!current_lesson.user_liked"
                            :user-note="current_lesson.user_note ?? ''"
                            :navigation="lesson_navigation"
                            :lesson-url="lessonUrl"
                            :module-lesson-url="moduleLessonUrl"
                            show-lessons-button
                            @toggle-cinema="toggleCinemaMode()"
                            @complete="markComplete"
                            @open-lessons="mobileSidebarOpen = true"
                        />

                        <MemberLessonMaterials :lesson="current_lesson" />

                        <MemberLessonComments
                            :comments="lesson_comments"
                            :comments-enabled="comments_enabled"
                            :comments-require-approval="comments_require_approval"
                            :submitting="commentSubmitting"
                            @submit="submitComment"
                        />
                    </div>

                    <aside class="lesson-sidebar hidden min-h-0 lg:block">
                        <MemberLessonSidebar
                            class="h-full"
                            :module="module"
                            :lessons="lessons"
                            :current-lesson-id="current_lesson.id"
                            :slug="slug"
                            :progress-percent="progress_percent"
                            :course-progress="courseProgress"
                            :is-lesson-completed="isLessonCompletedFn"
                            :lesson-url="lessonUrl"
                            :module-lesson-url="moduleLessonUrl"
                            :next-modules="next_modules"
                        />
                    </aside>
                </div>
            </template>

            <div
                v-else
                class="relative flex flex-col gap-6 lg:flex-row lg:items-start lg:gap-8"
            >
            <main class="relative z-0 min-w-0 flex-1 space-y-5">
                    <div class="rounded-2xl bg-black/20 p-12 text-center">
                        <p class="text-zinc-400">Selecione uma aula na lista ao lado.</p>
                        <Link :href="`/m/${slug}`" class="mt-4 inline-block text-sm text-[var(--ma-primary)] hover:underline">← Voltar ao início</Link>
                    </div>
            </main>

            <aside class="lesson-sidebar hidden min-h-0 w-80 shrink-0 lg:block">
                <MemberLessonSidebar
                    class="h-full"
                    :module="module"
                    :lessons="lessons"
                    :current-lesson-id="current_lesson?.id ?? null"
                    :slug="slug"
                    :progress-percent="progress_percent"
                    :course-progress="courseProgress"
                    :is-lesson-completed="isLessonCompletedFn"
                    :lesson-url="lessonUrl"
                    :module-lesson-url="moduleLessonUrl"
                    :next-modules="next_modules"
                />
            </aside>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="mobileSidebarOpen"
                class="fixed inset-0 z-50 lg:hidden"
                role="dialog"
                aria-modal="true"
            >
                <div class="absolute inset-0 bg-black/90" @click="mobileSidebarOpen = false" />
                <div class="absolute inset-y-0 right-0 flex h-full w-full max-w-sm flex-col p-4">
                    <MemberLessonSidebar
                        mobile
                        class="min-h-0 flex-1"
                        :module="module"
                        :lessons="lessons"
                        :current-lesson-id="current_lesson?.id ?? null"
                        :slug="slug"
                        :progress-percent="progress_percent"
                        :course-progress="courseProgress"
                        :is-lesson-completed="isLessonCompletedFn"
                        :lesson-url="lessonUrl"
                        :module-lesson-url="moduleLessonUrl"
                        :next-modules="next_modules"
                        @close="mobileSidebarOpen = false"
                    />
                </div>
            </div>
        </Teleport>

        <section v-if="sections?.length && !current_lesson" class="pt-8">
            <h2 class="mb-4 text-xl font-semibold text-white">Outros módulos</h2>
            <div class="space-y-6">
                <div v-for="section in sections" :key="section.id" class="space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-medium text-zinc-400">{{ section.title }}</h3>
                        <div v-if="carouselHasOverflow[section.id]" class="flex shrink-0 items-center gap-1">
                            <button
                                type="button"
                                class="rounded-lg p-2 text-zinc-400 transition hover:bg-white/10 hover:text-white"
                                aria-label="Rolar para a esquerda"
                                @click="scrollCarousel(section.id, -1)"
                            >
                                <ChevronLeft class="h-5 w-5" />
                            </button>
                            <button
                                type="button"
                                class="rounded-lg p-2 text-zinc-400 transition hover:bg-white/10 hover:text-white"
                                aria-label="Rolar para a direita"
                                @click="scrollCarousel(section.id, 1)"
                            >
                                <ChevronRight class="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                    <div
                        :ref="(el) => setCarouselRef(section.id, el)"
                        class="no-scrollbar flex gap-4 overflow-x-auto"
                    >
                        <template v-for="mod in section.modules" :key="mod.id">
                            <Link
                                v-if="!mod.is_locked"
                                :href="`/m/${slug}/modulo/${mod.id}`"
                                class="flex w-64 shrink-0 flex-col overflow-hidden rounded-2xl bg-zinc-900/50 text-left transition hover:bg-zinc-900/70"
                                :class="{ 'bg-zinc-800/80': mod.id === module.id }"
                            >
                                <div :class="[(section.cover_mode === 'horizontal' ? 'aspect-video' : 'aspect-[2/3]'), 'relative w-full bg-zinc-800']">
                                    <img v-if="mod.thumbnail" :src="mod.thumbnail" :alt="mod.title" class="absolute inset-0 h-full w-full object-cover" />
                                    <div v-if="mod.show_title_on_cover !== false" class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/90 to-transparent px-3 pb-3 pt-8">
                                        <p class="truncate text-base font-medium text-white">{{ mod.title }}</p>
                                    </div>
                                </div>
                            </Link>
                            <div
                                v-else
                                class="flex w-64 shrink-0 cursor-not-allowed flex-col overflow-hidden rounded-2xl bg-zinc-900/30 opacity-70"
                            >
                                <div :class="[(section.cover_mode === 'horizontal' ? 'aspect-video' : 'aspect-[2/3]'), 'relative w-full bg-zinc-800']">
                                    <img v-if="mod.thumbnail" :src="mod.thumbnail" :alt="mod.title" class="absolute inset-0 h-full w-full object-cover" />
                                    <div class="absolute inset-0 bg-black/50" />
                                    <div class="absolute inset-x-0 bottom-0 px-3 pb-3 pt-8">
                                        <p class="truncate text-base font-medium text-white">{{ mod.title }}</p>
                                        <p v-if="mod.lock_message" class="mt-1 text-xs text-white/80">{{ mod.lock_message }}</p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
@media (min-width: 1024px) {
    /* Padrão Hotmart: conteúdo flexível à esquerda, trilho de aulas colado à direita */
    .lesson-stage {
        align-items: start;
        min-height: calc(100vh - 3.5rem - 1.5rem);
    }

    .lesson-video-shell {
        width: 100%;
    }

    .lesson-sidebar {
        position: sticky;
        top: 3.5rem;
        align-self: start;
        width: 100%;
        height: calc(100vh - 3.5rem);
        max-height: calc(100vh - 3.5rem);
        overflow: hidden;
        margin: 0;
        padding-right: 1.5rem; /* compensa -mr-6 e respira na borda */
        box-sizing: border-box;
    }
}
</style>
