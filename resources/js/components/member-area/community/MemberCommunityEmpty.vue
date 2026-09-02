<script setup>
import { MessageSquare } from 'lucide-vue-next';
</script>

<template>
    <div class="rounded-2xl bg-zinc-900/50 px-6 py-12 text-center">
        <span class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-[var(--ma-primary)]/15">
            <MessageSquare class="h-6 w-6 text-[var(--ma-primary)]" />
        </span>
        <p class="text-sm font-medium text-zinc-300">Nenhuma publicação ainda</p>
        <p class="mt-1 text-xs text-zinc-500">Seja o primeiro a compartilhar algo nesta página.</p>
    </div>
</template>
