<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { MessageSquare } from 'lucide-vue-next';
import { getCommunityPageIconComponent } from '@/utils/communityPageIcons';
import { COMMUNITY_BANNER_ASPECT_CLASS, COMMUNITY_BANNER_IMAGE_CLASS } from '@/utils/communityBanner';

const props = defineProps({
    pages: { type: Array, default: () => [] },
    basePath: { type: String, required: true },
    excludeSlug: { type: String, default: null },
});

const suggestedPages = computed(() =>
    props.pages
        .filter((p) => !p.is_featured && (!props.excludeSlug || p.slug !== props.excludeSlug))
        .slice(0, 4),
);
</script>

<template>
    <section v-if="suggestedPages.length" class="rounded-2xl bg-zinc-950/60 p-4 ring-1 ring-zinc-800/60">
        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-500">Outras páginas</h3>
        <ul class="space-y-3">
            <li v-for="p in suggestedPages" :key="p.id">
                <Link
                    :href="`${basePath}/${p.slug}`"
                    class="block overflow-hidden rounded-xl ring-1 ring-zinc-800/60 transition hover:ring-[var(--ma-primary)]/30"
                >
                    <div v-if="p.banner_url" :class="[COMMUNITY_BANNER_ASPECT_CLASS, 'relative w-full overflow-hidden bg-zinc-900/80']">
                        <img :src="p.banner_url" :alt="p.title" :class="COMMUNITY_BANNER_IMAGE_CLASS" />
                    </div>
                    <div class="flex items-center gap-2 bg-zinc-900/40 px-3 py-2.5">
                        <template v-if="p.icon">
                            <component
                                v-if="getCommunityPageIconComponent(p.icon)"
                                :is="getCommunityPageIconComponent(p.icon)"
                                class="h-4 w-4 shrink-0 text-[var(--ma-primary)]"
                            />
                            <span v-else class="text-base">{{ p.icon }}</span>
                        </template>
                        <MessageSquare v-else class="h-4 w-4 shrink-0 text-[var(--ma-primary)]" />
                        <span class="truncate text-sm font-medium text-zinc-200">{{ p.title }}</span>
                    </div>
                </Link>
            </li>
        </ul>
    </section>
</template>
