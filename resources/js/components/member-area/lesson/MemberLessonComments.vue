<script setup>
import { ref } from 'vue';
import Button from '@/components/ui/Button.vue';

defineProps({
    comments: { type: Array, default: () => [] },
    commentsEnabled: { type: Boolean, default: false },
    commentsRequireApproval: { type: Boolean, default: true },
    submitting: { type: Boolean, default: false },
});

const emit = defineEmits(['submit']);

const content = ref('');

function formatCommentDate(iso) {
    if (!iso) return '';
    try {
        const d = new Date(iso);
        return d.toLocaleDateString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch (_) {
        return iso;
    }
}

function onSubmit() {
    const trimmed = content.value?.trim();
    if (!trimmed) return;
    emit('submit', trimmed);
    content.value = '';
}
</script>

<template>
    <section v-if="commentsEnabled" class="rounded-2xl bg-zinc-900/50 p-5 lg:p-6">
        <h2 class="mb-5 text-sm font-semibold uppercase tracking-wide text-zinc-300">Deixe seu comentário</h2>

        <ul v-if="comments?.length" class="mb-6 space-y-4">
            <li
                v-for="c in comments"
                :key="c.id"
                class="flex gap-3 pb-4 last:pb-0"
            >
                <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[var(--ma-primary)]/20 text-sm font-semibold text-[var(--ma-primary)]">
                    <img v-if="c.user?.avatar_url" :src="c.user.avatar_url" :alt="c.user.name" class="h-full w-full object-cover" />
                    <span v-else>{{ (c.user?.name ?? 'A').split(/\s+/).map(n => n[0]).slice(0, 2).join('').toUpperCase() || 'A' }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <p class="text-sm font-medium text-zinc-200">{{ c.user?.name ?? 'Aluno' }}</p>
                        <p class="text-xs text-zinc-500">{{ formatCommentDate(c.created_at) }}</p>
                    </div>
                    <p class="mt-1 text-sm text-zinc-400">{{ c.content }}</p>
                </div>
            </li>
        </ul>
        <p v-else class="mb-4 text-sm text-zinc-500">Nenhum comentário ainda. Seja o primeiro!</p>

        <form class="flex flex-col items-stretch gap-3 sm:items-end" @submit.prevent="onSubmit">
            <textarea
                v-model="content"
                rows="3"
                class="w-full rounded-xl bg-zinc-800/60 px-4 py-3 text-sm text-white placeholder-zinc-500 focus:bg-zinc-800 focus:outline-none"
                placeholder="Escreva seu comentário…"
                maxlength="2000"
            />
            <Button type="submit" class="sm:w-auto" :disabled="submitting || !content?.trim()">
                {{ submitting ? 'Enviando…' : 'Enviar comentário' }}
            </Button>
        </form>
        <p v-if="commentsRequireApproval" class="mt-3 text-right text-xs text-zinc-500">
            Seus comentários serão publicados após aprovação do instrutor.
        </p>
    </section>
</template>
