<script setup>
import { ref, watch, onMounted } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import axios from 'axios';
import MemberAreaAppLayout from '@/Layouts/MemberAreaAppLayout.vue';
import MemberCommunityLayout from '@/components/member-area/community/MemberCommunityLayout.vue';
import MemberCommunityRightRail from '@/components/member-area/community/MemberCommunityRightRail.vue';
import MemberCommunityBanner from '@/components/member-area/community/MemberCommunityBanner.vue';
import MemberCommunityComposer from '@/components/member-area/community/MemberCommunityComposer.vue';
import MemberCommunityPost from '@/components/member-area/community/MemberCommunityPost.vue';
import MemberCommunityEmpty from '@/components/member-area/community/MemberCommunityEmpty.vue';
import { scrollToCommunityPost } from '@/utils/communityPost';

defineOptions({ layout: MemberAreaAppLayout });

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    auth_user_id: { type: Number, default: null },
    can_delete_any_post: { type: Boolean, default: false },
    community_users_can_delete_own_posts: { type: Boolean, default: true },
    pages: { type: Array, default: () => [] },
    page: { type: Object, required: true },
    posts: { type: Object, required: true },
    featured_posts: { type: Array, default: () => [] },
    featured_pages: { type: Array, default: () => [] },
    cross_page_posts: { type: Array, default: () => [] },
    active_members: { type: Array, default: () => [] },
    slug: { type: String, required: true },
});

const postsList = ref(props.posts?.data ? [...props.posts.data] : []);
watch(() => props.posts?.data, (data) => {
    postsList.value = data ? [...data] : [];
}, { immediate: true });

const postForm = useForm({ content: '', image: null, video: null, media_aspect: '4:5' });
const postMediaPreviewUrl = ref('');
const postMediaKind = ref(null);
const likeLoadingId = ref(null);
const commentLoadingId = ref(null);
const commentContentByPost = ref({});

const basePath = `/m/${props.slug}/comunidade`;
const postsBase = () => `${basePath}/${props.page.slug}/posts`;
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

onMounted(() => {
    const hash = window.location.hash;
    if (hash.startsWith('#post-')) {
        const id = hash.replace('#post-', '');
        if (id) setTimeout(() => scrollToCommunityPost(id), 300);
    }
});

function canDeletePost(post) {
    if (props.can_delete_any_post) return true;
    if (post.user_id === props.auth_user_id && props.community_users_can_delete_own_posts) return true;
    return false;
}

function onPostMediaChange(event) {
    const file = event.target?.files?.[0];
    if (!file) return;
    if (file.type.startsWith('video/')) {
        postForm.video = file;
        postForm.image = null;
        postForm.media_aspect = '9:16';
        postMediaKind.value = 'video';
    } else {
        postForm.image = file;
        postForm.video = null;
        postMediaKind.value = 'image';
        if (postForm.media_aspect === '9:16') {
            postForm.media_aspect = '4:5';
        }
    }
    postMediaPreviewUrl.value = URL.createObjectURL(file);
    event.target.value = '';
}
function clearPostMedia() {
    postForm.image = null;
    postForm.video = null;
    postMediaPreviewUrl.value = '';
    postMediaKind.value = null;
}
function submitPost() {
    postForm.post(`/m/${props.slug}/comunidade/${props.page.slug}/posts`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            postForm.reset('content', 'image', 'video');
            postForm.media_aspect = '4:5';
            clearPostMedia();
        },
    });
}

function deletePost(post) {
    if (!confirm('Excluir esta postagem?')) return;
    router.delete(`${postsBase()}/${post.id}`, { preserveScroll: true });
}
async function toggleLike(post) {
    const idx = postsList.value.findIndex((p) => p.id === post.id);
    if (idx < 0) return;
    likeLoadingId.value = post.id;
    try {
        const url = `${postsBase()}/${post.id}/like`;
        if (post.user_has_liked) {
            const { data } = await axios.delete(url, { headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' } });
            postsList.value[idx] = { ...postsList.value[idx], likes_count: data.likes_count, user_has_liked: data.user_has_liked };
        } else {
            const { data } = await axios.post(url, {}, { headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' } });
            postsList.value[idx] = { ...postsList.value[idx], likes_count: data.likes_count, user_has_liked: data.user_has_liked };
        }
    } finally {
        likeLoadingId.value = null;
    }
}
function getCommentContent(postId) {
    return commentContentByPost.value[postId] ?? '';
}
function setCommentContent(postId, value) {
    commentContentByPost.value[postId] = value;
}
async function submitComment(post) {
    const content = (commentContentByPost.value[post.id] ?? '').trim();
    if (!content) return;
    const idx = postsList.value.findIndex((p) => p.id === post.id);
    if (idx < 0) return;
    commentLoadingId.value = post.id;
    try {
        const { data } = await axios.post(`${postsBase()}/${post.id}/comments`, { content }, { headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' } });
        const nextComments = [...(postsList.value[idx].comments ?? []), data.comment];
        postsList.value[idx] = { ...postsList.value[idx], comments: nextComments };
        setCommentContent(post.id, '');
    } finally {
        commentLoadingId.value = null;
    }
}
</script>

<template>
    <MemberCommunityLayout
        :pages="pages"
        :base-path="basePath"
        :active-slug="page.slug"
    >
        <MemberCommunityBanner :page="page" />

        <MemberCommunityComposer
            v-if="page.is_public_posting"
            :content="postForm.content"
            :processing="postForm.processing"
            :media-aspect="postForm.media_aspect"
            :media-preview-url="postMediaPreviewUrl"
            :media-kind="postMediaKind"
            :errors="postForm.errors"
            @update:content="postForm.content = $event"
            @update:media-aspect="postForm.media_aspect = $event"
            @submit="submitPost"
            @media-change="onPostMediaChange"
            @clear-media="clearPostMedia"
        />
        <p v-else class="rounded-2xl bg-zinc-950/60 px-4 py-3 text-sm text-zinc-500 ring-1 ring-zinc-800/60">
            Apenas o instrutor pode publicar nesta página.
        </p>

        <div v-if="postsList.length" class="space-y-4">
            <MemberCommunityPost
                v-for="post in postsList"
                :key="post.id"
                :post="post"
                :can-delete="canDeletePost(post)"
                :like-loading="likeLoadingId === post.id"
                :comment-loading="commentLoadingId === post.id"
                :comment-content="getCommentContent(post.id)"
                @delete="deletePost"
                @toggle-like="toggleLike"
                @update:comment-content="setCommentContent(post.id, $event)"
                @submit-comment="submitComment"
            />
        </div>
        <MemberCommunityEmpty v-else />

        <div v-if="posts.prev_page_url || posts.next_page_url" class="flex gap-2">
            <a
                v-if="posts.prev_page_url"
                :href="posts.prev_page_url"
                class="rounded-xl bg-zinc-800/60 px-4 py-2 text-sm text-zinc-300 transition hover:bg-zinc-800"
            >
                Anterior
            </a>
            <a
                v-if="posts.next_page_url"
                :href="posts.next_page_url"
                class="rounded-xl bg-zinc-800/60 px-4 py-2 text-sm text-zinc-300 transition hover:bg-zinc-800"
            >
                Próxima
            </a>
        </div>

        <template #right>
            <MemberCommunityRightRail
                :pages="pages"
                :base-path="basePath"
                :active-slug="page.slug"
                :featured-posts="featured_posts"
                :featured-pages="featured_pages"
                :cross-page-posts="cross_page_posts"
                :active-members="active_members"
            />
        </template>
    </MemberCommunityLayout>
</template>
