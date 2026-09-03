<script setup>
import MemberCommunitySidebar from '@/components/member-area/community/MemberCommunitySidebar.vue';

defineOptions({ inheritAttrs: false });

defineProps({
    pages: { type: Array, default: () => [] },
    basePath: { type: String, required: true },
    activeSlug: { type: String, default: null },
});
</script>

<template>
    <div
        class="flex w-full flex-col gap-6 lg:h-[calc(100dvh-3.5rem-1.5rem)] lg:min-h-0 lg:flex-row lg:items-stretch lg:gap-6 lg:overflow-hidden"
    >
        <div class="w-full shrink-0 lg:flex lg:h-full lg:w-64 lg:min-h-0 lg:flex-col">
            <MemberCommunitySidebar
                :pages="pages"
                :base-path="basePath"
                :active-slug="activeSlug"
            />
        </div>

        <main class="no-scrollbar min-h-0 min-w-0 flex-1 space-y-5 lg:overflow-y-auto lg:overscroll-contain lg:pb-2">
            <slot />
        </main>

        <aside class="hidden min-h-0 w-72 shrink-0 lg:block lg:overflow-y-auto lg:overscroll-contain lg:pb-2">
            <slot name="right" />
        </aside>
    </div>
</template>
