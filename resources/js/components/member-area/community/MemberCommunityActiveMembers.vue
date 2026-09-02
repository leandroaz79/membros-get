<script setup>
import { computed } from 'vue';
import { getPostInitials } from '@/utils/communityPost';

const props = defineProps({
    members: { type: Array, default: () => [] },
});

const hasMembers = computed(() => props.members.length > 0);
</script>

<template>
    <section v-if="hasMembers" class="rounded-2xl bg-zinc-950/60 p-4 ring-1 ring-zinc-800/60">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Membros mais ativos</h3>
        <p class="mb-3 mt-1 text-[11px] leading-relaxed text-zinc-600">Quem mais publica na comunidade.</p>
        <ul class="space-y-2">
            <li
                v-for="(member, index) in members"
                :key="member.id"
                class="flex items-center gap-3 rounded-xl p-2"
            >
                <span class="flex h-5 w-5 shrink-0 items-center justify-center text-[10px] font-semibold text-zinc-600">
                    {{ index + 1 }}
                </span>
                <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[var(--ma-primary)]/15 text-xs font-semibold text-[var(--ma-primary)]">
                    <img v-if="member.avatar_url" :src="member.avatar_url" :alt="member.name" class="h-full w-full object-cover" />
                    <span v-else>{{ getPostInitials(member.name) }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs font-medium text-zinc-300">{{ member.name }}</p>
                    <p class="text-[10px] text-zinc-600">
                        {{ member.posts_count }} {{ member.posts_count === 1 ? 'publicação' : 'publicações' }}
                    </p>
                </div>
            </li>
        </ul>
    </section>
</template>
