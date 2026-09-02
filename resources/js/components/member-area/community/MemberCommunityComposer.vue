<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import { ImagePlus, X } from 'lucide-vue-next';
import MemberCommunityPostMedia from '@/components/member-area/community/MemberCommunityPostMedia.vue';
import {
    COMMUNITY_IMAGE_ASPECT_OPTIONS,
    COMMUNITY_MEDIA_ASPECTS,
    COMMUNITY_VIDEO_ASPECT,
    COMMUNITY_VIDEO_RECOMMENDED,
} from '@/utils/communityPostMedia';

const props = defineProps({
    content: { type: String, default: '' },
    processing: { type: Boolean, default: false },
    mediaAspect: { type: String, default: '4:5' },
    mediaPreviewUrl: { type: String, default: '' },
    mediaKind: { type: String, default: null },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits([
    'update:content',
    'update:media-aspect',
    'submit',
    'media-change',
    'clear-media',
]);

const mediaInputRef = ref(null);
const page = usePage();
const user = computed(() => page.props.auth?.user ?? page.props.user ?? null);

const previewAspect = computed(() => (
    props.mediaKind === 'video' ? COMMUNITY_VIDEO_ASPECT : props.mediaAspect
));

const mediaHint = computed(() => (
    props.mediaKind === 'video'
        ? `Vídeo vertical recomendado: ${COMMUNITY_VIDEO_RECOMMENDED}`
        : 'Imagens em 1:1 (quadrado) ou 4:5 (retrato), estilo Instagram.'
));

function getInitials(name) {
    if (!name) return 'A';
    return name.split(/\s+/).map((n) => n[0]).slice(0, 2).join('').toUpperCase() || 'A';
}

function selectImageAspect(aspect) {
    if (props.mediaKind === 'video') return;
    emit('update:media-aspect', aspect);
}

function openMediaPicker() {
    mediaInputRef.value?.click();
}
</script>

<template>
    <form class="rounded-2xl bg-zinc-950/60 p-5 shadow-xl shadow-black/20 ring-1 ring-zinc-800/80" @submit.prevent="emit('submit')">
        <div class="flex gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[var(--ma-primary)]/15 text-sm font-semibold text-[var(--ma-primary)]">
                <img v-if="user?.avatar_url" :src="user.avatar_url" :alt="user.name" class="h-full w-full object-cover" />
                <span v-else>{{ getInitials(user?.name) }}</span>
            </div>
            <div class="min-w-0 flex-1 space-y-3">
                <textarea
                    :value="content"
                    rows="3"
                    class="w-full resize-none rounded-xl border-0 bg-zinc-800/80 px-4 py-3 text-sm text-white placeholder-zinc-500 ring-1 ring-zinc-700/60 transition focus:outline-none focus:ring-[var(--ma-primary)]"
                    placeholder="Escreva um post..."
                    required
                    @input="emit('update:content', $event.target.value)"
                />

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="aspect in COMMUNITY_IMAGE_ASPECT_OPTIONS"
                        :key="aspect"
                        type="button"
                        :disabled="mediaKind === 'video'"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-xs font-medium transition ring-1',
                            mediaAspect === aspect && mediaKind !== 'video'
                                ? 'bg-[var(--ma-primary)]/15 text-[var(--ma-primary)] ring-[var(--ma-primary)]/40'
                                : 'bg-zinc-800/60 text-zinc-400 ring-zinc-700/60 hover:text-zinc-200 disabled:opacity-40',
                        ]"
                        @click="selectImageAspect(aspect)"
                    >
                        {{ COMMUNITY_MEDIA_ASPECTS[aspect].label }}
                        <span class="ml-1 hidden sm:inline">{{ COMMUNITY_MEDIA_ASPECTS[aspect].description }}</span>
                    </button>
                    <span
                        v-if="mediaKind === 'video'"
                        class="inline-flex items-center rounded-lg bg-[var(--ma-primary)]/15 px-3 py-1.5 text-xs font-medium text-[var(--ma-primary)] ring-1 ring-[var(--ma-primary)]/40"
                    >
                        {{ COMMUNITY_MEDIA_ASPECTS[COMMUNITY_VIDEO_ASPECT].label }} · Vídeo
                    </span>
                </div>

                <p class="text-[11px] leading-relaxed text-zinc-600">{{ mediaHint }}</p>

                <div class="flex flex-wrap items-end gap-3">
                    <input
                        ref="mediaInputRef"
                        type="file"
                        accept="image/*,video/mp4,video/webm,video/quicktime"
                        class="hidden"
                        @change="emit('media-change', $event)"
                    />
                    <button
                        type="button"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-zinc-800/60 text-zinc-300 transition hover:bg-zinc-800 hover:text-white"
                        title="Anexar imagem ou vídeo"
                        aria-label="Anexar imagem ou vídeo"
                        @click="openMediaPicker"
                    >
                        <ImagePlus class="h-5 w-5" />
                    </button>

                    <div v-if="mediaPreviewUrl" class="relative max-w-[220px] flex-1">
                        <MemberCommunityPostMedia
                            :image-url="mediaKind === 'image' ? mediaPreviewUrl : null"
                            :video-url="mediaKind === 'video' ? mediaPreviewUrl : null"
                            :media-aspect="previewAspect"
                        />
                        <button
                            type="button"
                            class="absolute -right-1 -top-1 rounded-full bg-zinc-800 p-1 text-zinc-200 ring-1 ring-zinc-700 hover:bg-zinc-700"
                            aria-label="Remover mídia"
                            @click="emit('clear-media')"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
                    </div>

                    <Button type="submit" class="ml-auto" :disabled="processing">
                        {{ processing ? 'Publicando…' : 'Publicar' }}
                    </Button>
                </div>

                <p v-if="errors.content" class="text-sm text-red-400">{{ errors.content }}</p>
                <p v-if="errors.image" class="text-sm text-red-400">{{ errors.image }}</p>
                <p v-if="errors.video" class="text-sm text-red-400">{{ errors.video }}</p>
                <p v-if="errors.media_aspect" class="text-sm text-red-400">{{ errors.media_aspect }}</p>
            </div>
        </div>
    </form>
</template>
