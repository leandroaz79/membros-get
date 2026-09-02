<script setup>
import { ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { ChevronLeft, ChevronRight, CheckCircle, Heart, List, Pencil } from 'lucide-vue-next';

const props = defineProps({
    title: { type: String, default: '' },
    slug: { type: String, required: true },
    lessonId: { type: Number, default: null },
    baseUrl: { type: String, default: '' },
    cinemaMode: { type: Boolean, default: false },
    completed: { type: Boolean, default: false },
    completing: { type: Boolean, default: false },
    likesCount: { type: Number, default: 0 },
    userLiked: { type: Boolean, default: false },
    userNote: { type: String, default: '' },
    navigation: {
        type: Object,
        default: () => ({ prev: null, next: null, next_module: null }),
    },
    lessonUrl: { type: Function, required: true },
    moduleLessonUrl: { type: Function, default: null },
    showLessonsButton: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle-cinema', 'complete', 'open-lessons']);

const liked = ref(props.userLiked);
const likesCount = ref(props.likesCount);
const likeLoading = ref(false);
const noteText = ref(props.userNote || '');
const lastSavedNote = ref(props.userNote || '');
const noteSaving = ref(false);
let noteSaveTimer = null;

watch(() => props.userLiked, (v) => { liked.value = v; });
watch(() => props.likesCount, (v) => { likesCount.value = v; });
watch(() => props.userNote, (v) => {
    noteText.value = v || '';
    lastSavedNote.value = v || '';
});
watch(() => props.lessonId, () => {
    liked.value = props.userLiked;
    likesCount.value = props.likesCount;
    noteText.value = props.userNote || '';
    lastSavedNote.value = props.userNote || '';
});

function apiBase() {
    const u = (props.baseUrl || '').trim();
    if (u) return u.replace(/\/$/, '');
    return `/m/${props.slug}`;
}

async function toggleLike() {
    if (!props.lessonId || likeLoading.value) return;
    likeLoading.value = true;
    try {
        const { data } = await axios.post(
            `${apiBase()}/aula/${props.lessonId}/like`,
            {},
            { headers: { Accept: 'application/json' } },
        );
        liked.value = !!data.liked;
        likesCount.value = Number(data.likes_count) || 0;
    } catch (_) {
        /* ignore */
    } finally {
        likeLoading.value = false;
    }
}

function scheduleNoteSave() {
    if (!props.lessonId) return;
    if (noteSaveTimer) clearTimeout(noteSaveTimer);
    noteSaveTimer = setTimeout(saveNote, 600);
}

async function saveNote() {
    if (!props.lessonId || noteSaving.value) return;
    if (noteText.value === lastSavedNote.value) return;
    noteSaving.value = true;
    try {
        await axios.put(
            `${apiBase()}/aula/${props.lessonId}/notes`,
            { notes: noteText.value },
            { headers: { Accept: 'application/json' } },
        );
        lastSavedNote.value = noteText.value;
    } catch (_) {
        /* ignore */
    } finally {
        noteSaving.value = false;
    }
}
</script>

<template>
    <div class="relative z-10 w-full min-w-0 max-w-full" :class="cinemaMode ? 'space-y-2' : 'space-y-3'">
        <h1 v-if="!cinemaMode" class="min-w-0 truncate text-lg font-bold leading-snug tracking-tight text-white lg:text-xl">
            {{ title }}
        </h1>

        <div class="flex flex-col" :class="cinemaMode ? 'gap-0' : 'gap-2'">
            <div
                class="flex min-w-0 flex-nowrap items-center gap-2 sm:flex-wrap"
                :class="cinemaMode ? 'justify-center' : 'lg:justify-end'"
            >
                <button
                    v-if="showLessonsButton"
                    type="button"
                    class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-zinc-800/60 px-2.5 text-xs font-medium text-zinc-300 transition hover:bg-zinc-800 lg:hidden"
                    @click="emit('open-lessons')"
                >
                    <List class="h-3.5 w-3.5" />
                    Aulas
                </button>

                <label class="hidden h-8 cursor-pointer select-none items-center gap-2 rounded-lg bg-zinc-800/60 px-2.5 lg:inline-flex">
                    <span class="text-xs font-medium text-zinc-400">Modo cinema</span>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="cinemaMode"
                        class="relative h-4 w-8 shrink-0 rounded-full transition-colors duration-200"
                        :class="cinemaMode ? 'bg-[var(--ma-primary)]' : 'bg-zinc-600'"
                        @click="emit('toggle-cinema')"
                    >
                        <span
                            class="absolute top-0.5 left-0.5 h-3 w-3 rounded-full bg-white shadow transition-transform duration-200"
                            :class="cinemaMode ? 'translate-x-4' : 'translate-x-0'"
                        />
                    </button>
                </label>

                <button
                    type="button"
                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-zinc-800/60 transition hover:bg-zinc-800 disabled:opacity-50"
                    :disabled="likeLoading || !lessonId"
                    :aria-pressed="liked"
                    :title="liked ? 'Remover curtida' : 'Curtir aula'"
                    @click="toggleLike"
                >
                    <Heart
                        class="h-3.5 w-3.5 transition"
                        :class="liked ? 'fill-[var(--ma-primary)] text-[var(--ma-primary)]' : 'text-zinc-400'"
                    />
                </button>

                <div class="flex h-8 min-w-0 flex-1 items-center gap-2 rounded-lg bg-zinc-800/60 px-2.5 sm:flex-none sm:min-w-[180px] lg:min-w-[220px]">
                    <Pencil class="h-3.5 w-3.5 shrink-0 text-zinc-500" />
                    <input
                        v-model="noteText"
                        type="text"
                        class="min-w-0 w-0 flex-1 bg-transparent text-xs text-white placeholder-zinc-500 focus:outline-none"
                        placeholder="Adicionar anotação"
                        maxlength="5000"
                        @input="scheduleNoteSave"
                        @blur="saveNote"
                    />
                    <span v-if="noteSaving" class="shrink-0 text-[10px] text-zinc-500">…</span>
                </div>
            </div>

            <div v-if="!cinemaMode" class="flex w-full min-w-0 max-w-full flex-wrap items-center justify-start gap-2 lg:justify-end">
                <Link
                    v-if="navigation.prev"
                    :href="lessonUrl(navigation.prev.id)"
                    class="inline-flex max-w-[11rem] items-center gap-1.5 rounded-lg bg-zinc-800/50 px-3 py-2 text-xs font-medium text-zinc-300 transition hover:bg-zinc-800 hover:text-white"
                >
                    <ChevronLeft class="h-3.5 w-3.5 shrink-0 text-[var(--ma-primary)]" />
                    <span class="truncate">{{ navigation.prev.title }}</span>
                </Link>
                <Link
                    v-else
                    :href="`/m/${slug}`"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-800/50 px-3 py-2 text-xs font-medium text-zinc-300 transition hover:bg-zinc-800 hover:text-white"
                >
                    <ChevronLeft class="h-3.5 w-3.5 shrink-0 text-[var(--ma-primary)]" />
                    Voltar ao início
                </Link>

                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-50"
                    :class="completed
                        ? 'bg-emerald-500/10 text-emerald-400'
                        : 'bg-[var(--ma-primary)]/15 text-[var(--ma-primary)] hover:bg-[var(--ma-primary)]/25'"
                    :disabled="completed || completing"
                    @click="emit('complete')"
                >
                    <CheckCircle class="h-3.5 w-3.5 shrink-0" />
                    {{ completed ? 'Concluída' : completing ? 'Salvando…' : 'Concluir aula' }}
                </button>

                <Link
                    v-if="navigation.next"
                    :href="lessonUrl(navigation.next.id)"
                    class="inline-flex max-w-[11rem] items-center gap-1.5 rounded-lg bg-zinc-800/50 px-3 py-2 text-xs font-medium text-zinc-300 transition hover:bg-zinc-800 hover:text-white"
                >
                    <span class="truncate">{{ navigation.next.title }}</span>
                    <ChevronRight class="h-3.5 w-3.5 shrink-0 text-[var(--ma-primary)]" />
                </Link>
                <Link
                    v-else-if="navigation.next_module && moduleLessonUrl"
                    :href="moduleLessonUrl(navigation.next_module.id, navigation.next_module.first_lesson_id)"
                    class="inline-flex max-w-[12rem] items-center gap-1.5 rounded-lg bg-zinc-800/50 px-3 py-2 text-xs font-medium text-zinc-300 transition hover:bg-zinc-800 hover:text-white"
                >
                    <span class="truncate">{{ navigation.next_module.title }}</span>
                    <ChevronRight class="h-3.5 w-3.5 shrink-0 text-[var(--ma-primary)]" />
                </Link>
                <span
                    v-else
                    class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-800/30 px-3 py-2 text-xs font-medium text-zinc-600"
                >
                    Última aula
                    <ChevronRight class="h-3.5 w-3.5 shrink-0" />
                </span>
            </div>
        </div>
    </div>
</template>
