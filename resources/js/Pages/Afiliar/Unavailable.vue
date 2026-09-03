<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AfiliarLayout from '@/Layouts/AfiliarLayout.vue';

defineOptions({ layout: AfiliarLayout });

const props = defineProps({
    slug: { type: String, required: true },
    reason: { type: String, default: 'disabled' },
});

const message = computed(() => {
    if (props.reason === 'inactive_product') {
        return 'Este produto não está disponível para novas afiliações no momento.';
    }

    return 'O programa de afiliados ainda não está ativo. Peça ao produtor para marcar "Ativar afiliação para este produto" e salvar.';
});
</script>

<template>
    <article class="overflow-hidden rounded-2xl border border-zinc-200 bg-white p-8 text-center shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <p class="text-xs font-semibold uppercase tracking-wider text-amber-600 dark:text-amber-400">
            Indisponível
        </p>
        <h1 class="mt-3 text-xl font-bold text-zinc-900 dark:text-white">
            Programa de afiliados indisponível
        </h1>
        <p class="mx-auto mt-4 max-w-md text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
            {{ message }}
        </p>
        <Link
            href="/login"
            class="mt-8 inline-flex rounded-xl border border-zinc-200 px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
        >
            Ir para o login
        </Link>
    </article>
</template>
