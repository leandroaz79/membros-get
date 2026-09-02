<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Heart, MessageCircle } from 'lucide-vue-next';
import { getPostInitials } from '@/utils/communityPost';

const props = defineProps({
    posts: { type: Array, default: () => [] },
    basePath: { type: String, required: true },
});

const hasPosts = computed(() => props.posts.length > 0);

function postUrl(post) {
    const slug = post.page?.slug;
    if (!slug) return props.basePath;
    return `${props.basePath}/${slug}#post-${post.id}`;
}
</script>

<template>
    <section v-if="hasPosts" class="rounded-2xl bg-zinc-950/60 p-4 ring-1 ring-zinc-800/60">
        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-500">De outras páginas</h3>
        <ul class="space-y-3">
            <li v-for="post in posts" :key="`${post.page?.slug}-${post.id}`">
                <Link
                    :href="postUrl(post)"
                    class="flex gap-3 rounded-xl p-2 transition hover:bg-zinc-800/50"
                >
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-zinc-800 text-xs font-semibold text-zinc-400">
                        <img v-if="post.user?.avatar_url" :src="post.user.avatar_url" :alt="post.user.name" class="h-full w-full object-cover" />
                        <span v-else>{{ getPostInitials(post.user?.name) }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[10px] font-medium uppercase tracking-wide text-[var(--ma-primary)]">{{ post.page?.title }}</p>
                        <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-zinc-400">{{ post.excerpt || post.content }}</p>
                        <div class="mt-1.5 flex items-center gap-3 text-[10px] text-zinc-600">
                            <span class="inline-flex items-center gap-1">
                                <Heart class="h-3 w-3" />
                                {{ post.likes_count ?? 0 }}
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <MessageCircle class="h-3 w-3" />
                                {{ post.comments_count ?? 0 }}
                            </span>
                        </div>
                    </div>
                </Link>
            </li>
        </ul>
    </section>
</template>
