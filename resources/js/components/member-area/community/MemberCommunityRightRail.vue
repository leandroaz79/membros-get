<script setup>
import MemberCommunityFeaturedPosts from '@/components/member-area/community/MemberCommunityFeaturedPosts.vue';
import MemberCommunityFeaturedPageCards from '@/components/member-area/community/MemberCommunityFeaturedPageCards.vue';
import MemberCommunityActiveMembers from '@/components/member-area/community/MemberCommunityActiveMembers.vue';
import MemberCommunityCrossPagePosts from '@/components/member-area/community/MemberCommunityCrossPagePosts.vue';
import MemberCommunitySuggestedPages from '@/components/member-area/community/MemberCommunitySuggestedPages.vue';

defineProps({
    pages: { type: Array, default: () => [] },
    basePath: { type: String, required: true },
    activeSlug: { type: String, default: null },
    featuredPosts: { type: Array, default: () => [] },
    featuredPages: { type: Array, default: () => [] },
    crossPagePosts: { type: Array, default: () => [] },
    activeMembers: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="space-y-4">
        <MemberCommunityFeaturedPosts
            :posts="featuredPosts"
            :page-slug="activeSlug"
            :base-path="basePath"
            :scope-label="activeSlug ? 'nesta página' : 'na comunidade'"
        />
        <MemberCommunityFeaturedPageCards :pages="featuredPages" :base-path="basePath" />
        <MemberCommunityActiveMembers :members="activeMembers" />
        <MemberCommunityCrossPagePosts :posts="crossPagePosts" :base-path="basePath" />
        <MemberCommunitySuggestedPages
            :pages="pages"
            :base-path="basePath"
            :exclude-slug="activeSlug"
        />
    </div>
</template>
