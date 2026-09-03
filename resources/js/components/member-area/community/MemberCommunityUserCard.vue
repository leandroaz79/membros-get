<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);

function getInitials(name) {
    if (!name) return 'A';
    return name.split(/\s+/).map((n) => n[0]).slice(0, 2).join('').toUpperCase() || 'A';
}
</script>

<template>
    <div v-if="user" class="flex items-center gap-3 border-b border-zinc-800/80 p-4">
        <div class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-[var(--ma-primary)]/15 text-sm font-semibold text-[var(--ma-primary)]">
            <img v-if="user.avatar_url" :src="user.avatar_url" :alt="user.name" class="h-full w-full object-cover" />
            <span v-else>{{ getInitials(user.name) }}</span>
        </div>
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-zinc-100">{{ user.name }}</p>
            <p class="truncate text-xs text-zinc-500">{{ user.email }}</p>
        </div>
    </div>
</template>
