<script setup>
import { computed } from 'vue';
import MemberAreaVideoPlayer from '@/components/MemberAreaVideoPlayer.vue';
import MemberPdfPresentationViewer from '@/components/MemberPdfPresentationViewer.vue';
import MemberPdfReader from '@/components/MemberPdfReader.vue';
import MemberLessonNextOverlay from '@/components/member-area/lesson/MemberLessonNextOverlay.vue';
import { formatLessonDescription } from '@/lib/utils';
import { Link as LinkIcon } from 'lucide-vue-next';

const props = defineProps({
    lesson: { type: Object, required: true },
    memberAreaBaseUrl: { type: String, required: true },
    cinemaMode: { type: Boolean, default: false },
    autoplayVideo: { type: Boolean, default: false },
    nextOverlayVisible: { type: Boolean, default: false },
    nextTarget: { type: Object, default: null },
    nextCountdown: { type: Number, default: 5 },
    nextCountdownTotal: { type: Number, default: 5 },
});

const emit = defineEmits(['ended', 'progress', 'last-page-reached', 'play-next', 'cancel-next']);

function normalizePdfFiles(lesson, defaultName = 'Material') {
    const list = Array.isArray(lesson?.content_files) ? lesson.content_files : [];
    const normalized = list
        .map((it) => {
            if (typeof it === 'string') return { url: it, name: defaultName };
            const url = (it?.url ?? '').toString().trim();
            if (!url) return null;
            return { url, name: (it?.name ?? defaultName).toString().trim() || defaultName };
        })
        .filter(Boolean);
    if (normalized.length === 0 && lesson?.content_url) {
        normalized.push({ url: lesson.content_url, name: defaultName });
    }
    return normalized;
}

function pdfProxyFiles(lesson, defaultName) {
    const norm = normalizePdfFiles(lesson, defaultName);
    const base = props.memberAreaBaseUrl.replace(/\/$/, '');
    return norm.map((f, i) => ({
        ...f,
        url: `${base}/aula/${lesson.id}/pdf/${i}`,
    }));
}

const presentationFiles = computed(() =>
    props.lesson?.type === 'pdf_presentation' ? pdfProxyFiles(props.lesson, 'Apresentação') : []
);

const pdfReaderFiles = computed(() =>
    props.lesson?.type === 'pdf_reader' ? pdfProxyFiles(props.lesson, 'Documento') : []
);

const showPdfDownloadsInContent = computed(() => {
    if (props.lesson?.type !== 'pdf') return false;
    return normalizePdfFiles(props.lesson).length === 0;
});
</script>

<template>
    <div
        class="min-w-0 max-w-full overflow-hidden rounded-2xl bg-zinc-950/90"
        :class="cinemaMode
            ? 'overflow-visible bg-transparent shadow-none'
            : 'shadow-2xl shadow-black/40'"
    >
        <template v-if="lesson.type === 'video'">
            <div class="relative min-w-0 max-w-full overflow-hidden">
                <MemberAreaVideoPlayer
                    v-if="lesson.content_url"
                    :key="`${lesson.id}-${autoplayVideo ? 'autoplay' : 'manual'}`"
                    :src="lesson.content_url"
                    :autoplay="autoplayVideo"
                    :watermark-enabled="!!lesson.watermark_enabled"
                    :watermark-data="lesson.student ?? null"
                    :theater="cinemaMode"
                    @ended="emit('ended')"
                    @progress="emit('progress', $event)"
                />
                <MemberLessonNextOverlay
                    :visible="nextOverlayVisible"
                    :next-target="nextTarget"
                    :countdown="nextCountdown"
                    :total-seconds="nextCountdownTotal"
                    @play-now="emit('play-next')"
                    @cancel="emit('cancel-next')"
                />
            </div>
            <div
                v-if="lesson.content_text && !cinemaMode"
                class="prose prose-invert max-w-none p-6"
                v-html="formatLessonDescription(lesson.content_text)"
            />
            <div v-if="!lesson.content_url && !lesson.content_text" class="flex aspect-video items-center justify-center p-8 text-zinc-500">
                Conteúdo não disponível.
            </div>
        </template>

        <template v-else-if="lesson.type === 'link' && lesson.content_url">
            <div class="flex min-h-[200px] items-center justify-center p-8">
                <a
                    :href="lesson.content_url"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-2 rounded-xl bg-[var(--ma-primary)] px-6 py-3 font-medium text-white transition hover:opacity-90"
                >
                    {{ lesson.link_title?.trim() || 'Abrir link externo' }}
                    <LinkIcon class="h-4 w-4" />
                </a>
            </div>
            <div
                v-if="lesson.content_text"
                class="prose prose-invert max-w-none p-6"
                v-html="formatLessonDescription(lesson.content_text)"
            />
        </template>

        <div
            v-else-if="lesson.type === 'link' && lesson.content_text"
            class="prose prose-invert max-w-none p-6"
            v-html="formatLessonDescription(lesson.content_text)"
        />

        <template v-else-if="lesson.type === 'pdf_presentation' && presentationFiles.length">
            <div class="p-4">
                <MemberPdfPresentationViewer :files="presentationFiles" />
            </div>
            <div
                v-if="lesson.content_text"
                class="prose prose-invert max-w-none p-6"
                v-html="formatLessonDescription(lesson.content_text)"
            />
        </template>

        <template v-else-if="lesson.type === 'pdf_reader' && pdfReaderFiles.length">
            <div class="p-4">
                <MemberPdfReader
                    :key="lesson.id"
                    :files="pdfReaderFiles"
                    :base-url="memberAreaBaseUrl"
                    :lesson-id="lesson.id"
                    :likes-count="lesson.likes_count ?? 0"
                    :user-liked="!!lesson.user_liked"
                    @last-page-reached="emit('last-page-reached')"
                />
            </div>
            <div
                v-if="lesson.content_text"
                class="prose prose-invert max-w-none p-6"
                v-html="formatLessonDescription(lesson.content_text)"
            />
        </template>

        <template v-else-if="lesson.type === 'pdf' && !showPdfDownloadsInContent">
            <div
                v-if="lesson.content_text"
                class="prose prose-invert max-w-none p-6"
                v-html="formatLessonDescription(lesson.content_text)"
            />
            <div v-else class="flex min-h-[160px] items-center justify-center p-8 text-zinc-500">
                Baixe os materiais abaixo.
            </div>
        </template>

        <template v-else-if="lesson.type === 'text' && lesson.content_text">
            <div class="prose prose-invert max-w-none p-6 lg:p-8" v-html="lesson.content_text" />
        </template>

        <div v-else class="flex min-h-[200px] items-center justify-center p-8 text-zinc-500">
            Conteúdo não disponível.
        </div>
    </div>
</template>
