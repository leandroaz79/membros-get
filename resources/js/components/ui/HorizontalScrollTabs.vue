<script setup>
defineProps({
    ariaLabel: { type: String, required: true },
    navClass: {
        type: String,
        default: 'gap-1 rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80',
    },
    bleed: { type: Boolean, default: true },
    wrapperClass: { type: String, default: '' },
});
</script>

<template>
    <div
        class="horizontal-scroll-tabs"
        :class="[bleed ? 'horizontal-scroll-tabs--bleed' : '', wrapperClass]"
    >
        <nav
            :aria-label="ariaLabel"
            class="horizontal-scroll-tabs__nav"
            :class="navClass"
        >
            <slot />
        </nav>
    </div>
</template>
