<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { scrollToCommunityPost } from '@/utils/communityPost';
import MemberCommunityFeaturedPostRow from '@/components/member-area/community/MemberCommunityFeaturedPostRow.vue';

const props = defineProps({
    posts: { type: Array, default: () => [] },
    pageSlug: { type: String, default: null },
    basePath: { type: String, default: null },
    scopeLabel: { type: String, default: 'nesta página' },
});

const hasPosts = computed(() => props.posts.length > 0);

function postUrl(post) {
    const slug = post.page?.slug ?? props.pageSlug;
    if (!slug || !props.basePath) return null;
    return `${props.basePath}/${slug}#post-${post.id}`;
}

function isSamePage(post) {
    if (!props.pageSlug) return false;
    return (post.page?.slug ?? props.pageSlug) === props.pageSlug;
}

function handleClick(post) {
    if (isSamePage(post)) {
        scrollToCommunityPost(post.id);
    }
}
</script>

<template>
    <section v-if="hasPosts" class="rounded-2xl bg-zinc-950/60 p-4 ring-1 ring-zinc-800/60">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Posts em destaque</h3>
        <p class="mb-3 mt-1 text-[11px] leading-relaxed text-zinc-600">
            Os 5 com mais curtidas e comentários {{ scopeLabel }}.
        </p>
        <ul class="space-y-3">
            <li v-for="post in posts" :key="post.id">
                <Link
                    v-if="!isSamePage(post) && postUrl(post)"
                    :href="postUrl(post)"
                    class="flex w-full gap-3 rounded-xl p-2 text-left transition hover:bg-zinc-800/50"
                >
                    <MemberCommunityFeaturedPostRow :post="post" show-page-label />
                </Link>
                <button
                    v-else
                    type="button"
                    class="flex w-full gap-3 rounded-xl p-2 text-left transition hover:bg-zinc-800/50"
                    @click="handleClick(post)"
                >
                    <MemberCommunityFeaturedPostRow :post="post" />
                </button>
            </li>
        </ul>
    </section>
</template>
