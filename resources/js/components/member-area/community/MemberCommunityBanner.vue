<script setup>
import { getCommunityPageIconComponent } from '@/utils/communityPageIcons';
import { COMMUNITY_BANNER_CONTAINER_CLASS, COMMUNITY_BANNER_IMAGE_CLASS } from '@/utils/communityBanner';

defineProps({
    page: { type: Object, required: true },
});
</script>

<template>
    <div v-if="page.banner_url" :class="COMMUNITY_BANNER_CONTAINER_CLASS">
        <img :src="page.banner_url" :alt="page.title" :class="COMMUNITY_BANNER_IMAGE_CLASS" />
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-zinc-950/70 via-transparent to-transparent" />
        <div class="absolute bottom-3 left-4 z-10 flex items-center gap-3 sm:bottom-4">
            <template v-if="page.icon">
                <span v-if="getCommunityPageIconComponent(page.icon)" class="flex h-10 w-10 items-center justify-center rounded-xl bg-black/30 backdrop-blur-sm">
                    <component :is="getCommunityPageIconComponent(page.icon)" class="h-6 w-6 text-white" />
                </span>
                <span v-else class="text-3xl drop-shadow">{{ page.icon }}</span>
            </template>
            <h1 class="text-xl font-bold tracking-tight text-white drop-shadow-sm sm:text-2xl">{{ page.title }}</h1>
        </div>
    </div>
    <div v-else class="flex items-center gap-3">
        <template v-if="page.icon">
            <span v-if="getCommunityPageIconComponent(page.icon)" class="flex h-12 w-12 items-center justify-center rounded-xl bg-[var(--ma-primary)]/15">
                <component :is="getCommunityPageIconComponent(page.icon)" class="h-6 w-6 text-[var(--ma-primary)]" />
            </span>
            <span v-else class="flex h-12 w-12 items-center justify-center rounded-xl bg-[var(--ma-primary)]/15 text-2xl">{{ page.icon }}</span>
        </template>
        <h1 class="text-2xl font-bold tracking-tight text-white">{{ page.title }}</h1>
    </div>
</template>
