<script setup>
import { Heart, MessageCircle } from 'lucide-vue-next';
import { getPostInitials } from '@/utils/communityPost';

defineProps({
    post: { type: Object, required: true },
    showPageLabel: { type: Boolean, default: false },
});
</script>

<template>
    <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[var(--ma-primary)]/15 text-xs font-semibold text-[var(--ma-primary)]">
        <img v-if="post.user?.avatar_url" :src="post.user.avatar_url" :alt="post.user.name" class="h-full w-full object-cover" />
        <span v-else>{{ getPostInitials(post.user?.name) }}</span>
    </div>
    <div class="min-w-0 flex-1">
        <p v-if="showPageLabel && post.page?.title" class="truncate text-[10px] font-medium uppercase tracking-wide text-[var(--ma-primary)]">
            {{ post.page.title }}
        </p>
        <p class="truncate text-xs font-medium text-zinc-300">{{ post.user?.name }}</p>
        <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-zinc-500">{{ post.excerpt || post.content }}</p>
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
</template>
