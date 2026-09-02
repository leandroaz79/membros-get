<script setup>
import { Link } from '@inertiajs/vue3';
import MemberAreaAppLayout from '@/Layouts/MemberAreaAppLayout.vue';
import MemberCommunityLayout from '@/components/member-area/community/MemberCommunityLayout.vue';
import MemberCommunityRightRail from '@/components/member-area/community/MemberCommunityRightRail.vue';
import { MessageSquare } from 'lucide-vue-next';
import { getCommunityPageIconComponent } from '@/utils/communityPageIcons';
import { COMMUNITY_BANNER_ASPECT_CLASS, COMMUNITY_BANNER_IMAGE_CLASS } from '@/utils/communityBanner';

defineOptions({ layout: MemberAreaAppLayout });

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    pages: { type: Array, default: () => [] },
    featured_posts: { type: Array, default: () => [] },
    featured_pages: { type: Array, default: () => [] },
    cross_page_posts: { type: Array, default: () => [] },
    active_members: { type: Array, default: () => [] },
    slug: { type: String, required: true },
});

const basePath = `/m/${props.slug}/comunidade`;
</script>

<template>
    <MemberCommunityLayout :pages="pages" :base-path="basePath">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white">Comunidade</h1>
            <p class="mt-1.5 text-sm text-zinc-400">Escolha uma página ao lado ou acesse diretamente:</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <Link
                v-for="p in pages"
                :key="p.id"
                :href="`${basePath}/${p.slug}`"
                class="group overflow-hidden rounded-2xl bg-zinc-950/60 ring-1 ring-zinc-800/60 transition hover:ring-[var(--ma-primary)]/30"
            >
                <div v-if="p.banner_url" :class="[COMMUNITY_BANNER_ASPECT_CLASS, 'relative w-full overflow-hidden bg-zinc-900/80']">
                    <img
                        :src="p.banner_url"
                        :alt="p.title"
                        :class="[COMMUNITY_BANNER_IMAGE_CLASS, 'transition duration-300 group-hover:scale-[1.02]']"
                    />
                </div>
                <div class="flex items-center gap-3 p-4">
                    <template v-if="p.icon">
                        <span v-if="getCommunityPageIconComponent(p.icon)" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--ma-primary)]/15">
                            <component :is="getCommunityPageIconComponent(p.icon)" class="h-5 w-5 text-[var(--ma-primary)]" />
                        </span>
                        <span v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--ma-primary)]/15 text-xl">{{ p.icon }}</span>
                    </template>
                    <img v-else-if="p.banner_url" :src="p.banner_url" :alt="p.title" class="h-10 w-10 shrink-0 rounded-xl object-cover" />
                    <div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--ma-primary)]/15">
                        <MessageSquare class="h-5 w-5 text-[var(--ma-primary)]" />
                    </div>
                    <span class="font-semibold text-zinc-200 transition group-hover:text-white">{{ p.title }}</span>
                </div>
            </Link>
        </div>

        <template #right>
            <MemberCommunityRightRail
                :pages="pages"
                :base-path="basePath"
                :featured-posts="featured_posts"
                :featured-pages="featured_pages"
                :cross-page-posts="cross_page_posts"
                :active-members="active_members"
            />
        </template>
    </MemberCommunityLayout>
</template>
