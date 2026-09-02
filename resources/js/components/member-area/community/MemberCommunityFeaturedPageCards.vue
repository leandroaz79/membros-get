<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { MessageSquare, Star } from 'lucide-vue-next';
import { getCommunityPageIconComponent } from '@/utils/communityPageIcons';
import { COMMUNITY_BANNER_ASPECT_CLASS, COMMUNITY_BANNER_IMAGE_CLASS } from '@/utils/communityBanner';

const props = defineProps({
    pages: { type: Array, default: () => [] },
    basePath: { type: String, required: true },
});

const hasPages = computed(() => props.pages.length > 0);
</script>

<template>
    <section v-if="hasPages" class="rounded-2xl bg-zinc-950/60 p-4 ring-1 ring-zinc-800/60">
        <div class="mb-3 flex items-center gap-2">
            <Star class="h-3.5 w-3.5 text-[var(--ma-primary)]" />
            <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Páginas em destaque</h3>
        </div>
        <ul class="space-y-3">
            <li v-for="p in pages" :key="p.id">
                <Link
                    :href="`${basePath}/${p.slug}`"
                    class="group block overflow-hidden rounded-xl ring-1 ring-zinc-800/60 transition hover:ring-[var(--ma-primary)]/40"
                >
                    <div
                        v-if="p.banner_url"
                        :class="[COMMUNITY_BANNER_ASPECT_CLASS, 'relative w-full overflow-hidden bg-zinc-900/80']"
                    >
                        <img
                            :src="p.banner_url"
                            :alt="p.title"
                            :class="[COMMUNITY_BANNER_IMAGE_CLASS, 'transition duration-300 group-hover:scale-[1.02]']"
                        />
                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 via-black/40 to-transparent px-3 pb-3 pt-10">
                            <p class="truncate text-sm font-semibold text-white">{{ p.title }}</p>
                        </div>
                    </div>
                    <div
                        v-else
                        class="flex items-center gap-3 bg-gradient-to-br from-[var(--ma-primary)]/10 to-zinc-900/80 px-4 py-5"
                    >
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[var(--ma-primary)]/15">
                            <component
                                v-if="p.icon && getCommunityPageIconComponent(p.icon)"
                                :is="getCommunityPageIconComponent(p.icon)"
                                class="h-5 w-5 text-[var(--ma-primary)]"
                            />
                            <span v-else-if="p.icon" class="text-xl">{{ p.icon }}</span>
                            <MessageSquare v-else class="h-5 w-5 text-[var(--ma-primary)]" />
                        </span>
                        <p class="truncate text-sm font-semibold text-zinc-100">{{ p.title }}</p>
                    </div>
                </Link>
            </li>
        </ul>
    </section>
</template>
