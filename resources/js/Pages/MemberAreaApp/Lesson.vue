<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import MemberAreaAppLayout from '@/Layouts/MemberAreaAppLayout.vue';
import MemberLessonContent from '@/components/member-area/lesson/MemberLessonContent.vue';
import MemberLessonMaterials from '@/components/member-area/lesson/MemberLessonMaterials.vue';
import MemberLessonComments from '@/components/member-area/lesson/MemberLessonComments.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: MemberAreaAppLayout });

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    lesson: { type: Object, required: true },
    slug: { type: String, required: true },
    base_url: { type: String, default: '' },
    comments_enabled: { type: Boolean, default: false },
    comments_require_approval: { type: Boolean, default: true },
    lesson_comments: { type: Array, default: () => [] },
});

const memberAreaBaseUrl = computed(() => {
    const u = (props.base_url || '').trim();
    if (u) return u.replace(/\/$/, '');
    return `/m/${props.slug}`;
});

const completed = ref(props.lesson.is_completed ?? false);
const completing = ref(false);
const commentSubmitting = ref(false);
let autoCompleteTimer = null;
const VIDEO_AUTO_COMPLETE_PERCENT = 60;

function markComplete() {
    if (completed.value || completing.value) return;
    completing.value = true;
    router.post(`/m/${props.slug}/aula/${props.lesson.id}/complete`, {}, {
        preserveScroll: true,
        onSuccess: () => { completed.value = true; },
        onFinish: () => { completing.value = false; },
    });
}

function onVideoProgress({ percent }) {
    if (completed.value || completing.value) return;
    if (percent >= VIDEO_AUTO_COMPLETE_PERCENT) {
        markComplete();
    }
}

function shouldAutoCompleteNonVideo() {
    if (!props.lesson || completed.value) return false;
    const t = props.lesson.type;
    if (t === 'pdf_presentation' || t === 'pdf_reader') return false;
    return t === 'link' || t === 'pdf' || t === 'text' || (t !== 'video' && (props.lesson.content_url || props.lesson.content_text));
}

onMounted(() => {
    if (props.lesson?.is_completed) completed.value = true;
    else if (shouldAutoCompleteNonVideo()) setTimeout(() => markComplete(), 500);
});

onUnmounted(() => {
    if (autoCompleteTimer) clearTimeout(autoCompleteTimer);
});

function submitComment(content) {
    if (!props.comments_enabled || !content) return;
    commentSubmitting.value = true;
    router.post(`/m/${props.slug}/aula/${props.lesson.id}/comments`, { content }, {
        preserveScroll: true,
        onFinish: () => { commentSubmitting.value = false; },
    });
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex flex-wrap items-center gap-2 text-sm text-zinc-400">
            <Link :href="`/m/${slug}`" class="hover:text-[var(--ma-primary)]">Início</Link>
            <span v-if="lesson.section"> / {{ lesson.section.title }}</span>
            <span v-if="lesson.module"> / {{ lesson.module.title }}</span>
        </div>

        <h1 class="text-2xl font-bold text-white">{{ lesson.title }}</h1>

        <MemberLessonContent
            :lesson="lesson"
            :member-area-base-url="memberAreaBaseUrl"
            @ended="markComplete"
            @progress="onVideoProgress"
            @last-page-reached="markComplete"
        />

        <div class="flex items-center justify-between gap-4">
            <Link :href="`/m/${slug}`" class="text-sm text-zinc-400 hover:text-[var(--ma-primary)]">← Voltar ao início</Link>
            <Button :disabled="completed || completing" @click="markComplete">
                {{ completed ? 'Aula concluída' : 'Concluir aula' }}
            </Button>
        </div>

        <MemberLessonMaterials :lesson="lesson" />

        <MemberLessonComments
            :comments="lesson_comments"
            :comments-enabled="comments_enabled"
            :comments-require-approval="comments_require_approval"
            :submitting="commentSubmitting"
            @submit="submitComment"
        />
    </div>
</template>
