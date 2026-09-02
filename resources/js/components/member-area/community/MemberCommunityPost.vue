<script setup>
import { ref, computed, watch } from 'vue';
import Button from '@/components/ui/Button.vue';
import { Heart, MessageCircle, Trash2 } from 'lucide-vue-next';
import { getPostInitials } from '@/utils/communityPost';
import MemberCommunityPostMedia from '@/components/member-area/community/MemberCommunityPostMedia.vue';

const props = defineProps({
    post: { type: Object, required: true },
    canDelete: { type: Boolean, default: false },
    likeLoading: { type: Boolean, default: false },
    commentLoading: { type: Boolean, default: false },
    commentContent: { type: String, default: '' },
});

const emit = defineEmits(['delete', 'toggle-like', 'update:comment-content', 'submit-comment']);

const commentsExpanded = ref((props.post.comments ?? []).length > 0);

watch(
    () => (props.post.comments ?? []).length,
    (count) => {
        if (count > 0) commentsExpanded.value = true;
    },
);

const commentsCount = computed(() => (props.post.comments ?? []).length);

function formatPostDate(isoString) {
    if (!isoString) return '';
    const d = new Date(isoString);
    return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <article :id="`post-${post.id}`" class="scroll-mt-4 rounded-2xl bg-zinc-950/60 p-5 ring-1 ring-zinc-800/60">
        <div class="flex items-start justify-between gap-3">
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[var(--ma-primary)]/15 text-sm font-semibold text-[var(--ma-primary)]">
                    <img v-if="post.user?.avatar_url" :src="post.user.avatar_url" :alt="post.user.name" class="h-full w-full object-cover" />
                    <span v-else>{{ getPostInitials(post.user?.name) }}</span>
                </div>
                <div class="min-w-0">
                    <p class="font-semibold text-zinc-100">{{ post.user?.name }}</p>
                    <p class="text-xs text-zinc-500">{{ formatPostDate(post.created_at) }}</p>
                </div>
            </div>
            <button
                v-if="canDelete"
                type="button"
                class="shrink-0 rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-800/60 hover:text-red-400"
                title="Excluir postagem"
                aria-label="Excluir postagem"
                @click="emit('delete', post)"
            >
                <Trash2 class="h-4 w-4" />
            </button>
        </div>

        <p class="mt-3 whitespace-pre-wrap text-sm leading-relaxed text-zinc-200">{{ post.content }}</p>
        <MemberCommunityPostMedia
            v-if="post.image_url || post.video_url"
            class="mt-3"
            :image-url="post.image_url"
            :video-url="post.video_url"
            :media-aspect="post.media_aspect"
        />

        <div class="mt-4 flex items-center gap-2 border-t border-zinc-800/80 pt-3">
            <button
                type="button"
                :class="[
                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition',
                    post.user_has_liked ? 'bg-red-500/10 text-red-400' : 'text-zinc-400 hover:bg-zinc-800/60 hover:text-zinc-200',
                ]"
                :disabled="likeLoading"
                @click="emit('toggle-like', post)"
            >
                <Heart :class="['h-4 w-4', post.user_has_liked ? 'fill-current' : '']" />
                <span>{{ post.likes_count ?? 0 }}</span>
            </button>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-400 transition hover:bg-zinc-800/60 hover:text-zinc-200"
                @click="commentsExpanded = !commentsExpanded"
            >
                <MessageCircle class="h-4 w-4" />
                Comentários ({{ commentsCount }})
            </button>
        </div>

        <div v-if="commentsExpanded" class="mt-3 space-y-2 border-t border-zinc-800/80 pt-3">
            <div v-for="c in (post.comments ?? [])" :key="c.id" class="flex gap-2">
                <div class="flex h-7 w-7 shrink-0 items-center justify-center overflow-hidden rounded-full bg-zinc-800 text-xs font-medium text-zinc-300">
                    <img v-if="c.user?.avatar_url" :src="c.user.avatar_url" :alt="c.user.name" class="h-full w-full object-cover" />
                    <span v-else>{{ getPostInitials(c.user?.name) }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium text-zinc-500">{{ c.user?.name }} · {{ c.created_at }}</p>
                    <p class="text-sm text-zinc-300">{{ c.content }}</p>
                </div>
            </div>
            <div class="flex gap-2 pt-1">
                <input
                    :value="commentContent"
                    type="text"
                    class="min-w-0 flex-1 rounded-xl border-0 bg-zinc-800/80 px-3 py-2 text-sm text-white placeholder-zinc-500 ring-1 ring-zinc-700/60 focus:outline-none focus:ring-[var(--ma-primary)]"
                    placeholder="Escreva um comentário..."
                    @input="emit('update:comment-content', $event.target.value)"
                    @keydown.enter.prevent="emit('submit-comment', post)"
                />
                <Button size="sm" :disabled="commentLoading || !commentContent?.trim()" @click="emit('submit-comment', post)">
                    Comentar
                </Button>
            </div>
        </div>
    </article>
</template>
