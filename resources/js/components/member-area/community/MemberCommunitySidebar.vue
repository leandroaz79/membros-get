<script setup>
import { Link } from '@inertiajs/vue3';
import { MessageSquare } from 'lucide-vue-next';
import { getCommunityPageIconComponent } from '@/utils/communityPageIcons';
import MemberCommunityUserCard from '@/components/member-area/community/MemberCommunityUserCard.vue';

defineProps({
    pages: { type: Array, default: () => [] },
    basePath: { type: String, required: true },
    activeSlug: { type: String, default: null },
});
</script>

<template>
    <aside class="flex h-full min-h-0 w-full flex-col overflow-hidden rounded-2xl bg-zinc-950/60 shadow-xl shadow-black/20 lg:w-full">
        <MemberCommunityUserCard />

        <nav class="min-h-0 flex-1 overflow-y-auto p-2">
            <Link
                v-for="p in pages"
                :key="p.id"
                :href="`${basePath}/${p.slug}`"
                :class="[
                    'relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                    activeSlug && p.slug === activeSlug
                        ? 'bg-[var(--ma-primary)]/10 text-[var(--ma-primary)]'
                        : 'text-zinc-400 hover:bg-zinc-800/60 hover:text-zinc-200',
                ]"
            >
                <span
                    v-if="activeSlug && p.slug === activeSlug"
                    class="absolute bottom-2 left-0 top-2 w-0.5 rounded-full bg-[var(--ma-primary)]"
                />
                <template v-if="p.icon">
                    <component
                        v-if="getCommunityPageIconComponent(p.icon)"
                        :is="getCommunityPageIconComponent(p.icon)"
                        class="h-4 w-4 shrink-0"
                        :class="activeSlug && p.slug === activeSlug ? 'text-[var(--ma-primary)]' : 'text-zinc-500'"
                    />
                    <span v-else class="text-lg leading-none">{{ p.icon }}</span>
                </template>
                <img v-else-if="p.banner_url" :src="p.banner_url" :alt="p.title" class="h-7 w-9 shrink-0 rounded-md object-cover" />
                <span v-else class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-[var(--ma-primary)]/15">
                    <MessageSquare class="h-3.5 w-3.5 text-[var(--ma-primary)]" />
                </span>
                <span class="truncate pl-0.5">{{ p.title }}</span>
            </Link>
        </nav>
    </aside>
</template>
