<script setup>
import { computed } from 'vue';
import { getCommunityMediaAspectClass, resolveCommunityMediaAspect } from '@/utils/communityPostMedia';

const props = defineProps({
    imageUrl: { type: String, default: null },
    videoUrl: { type: String, default: null },
    mediaAspect: { type: String, default: null },
    compact: { type: Boolean, default: false },
});

const aspectClass = computed(() => getCommunityMediaAspectClass(
    resolveCommunityMediaAspect(props.mediaAspect, Boolean(props.videoUrl)),
));

const hasMedia = computed(() => Boolean(props.imageUrl || props.videoUrl));
</script>

<template>
    <div
        v-if="hasMedia"
        :class="[
            'overflow-hidden rounded-xl bg-zinc-900/80 ring-1 ring-zinc-800/60',
            aspectClass,
            compact ? 'max-w-[120px]' : 'w-full',
        ]"
    >
        <img
            v-if="imageUrl"
            :src="imageUrl"
            alt=""
            class="h-full w-full object-contain object-center"
        />
        <video
            v-else-if="videoUrl"
            :src="videoUrl"
            controls
            playsinline
            preload="metadata"
            class="h-full w-full object-contain object-center"
        />
    </div>
</template>
