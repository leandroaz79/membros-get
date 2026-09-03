<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { CircleDollarSign, Repeat } from 'lucide-vue-next';
import HorizontalScrollTabs from '@/components/ui/HorizontalScrollTabs.vue';

const page = usePage();
const isVendas = computed(() => {
    const url = page.url;
    return url === '/vendas' || url.startsWith('/vendas?');
});
const isAssinaturas = computed(() => page.url.startsWith('/vendas/assinaturas'));
</script>

<template>
    <HorizontalScrollTabs aria-label="Abas de vendas">
        <Link
            href="/vendas"
            :class="[
                'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                isVendas
                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                    : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
            ]"
        >
            <CircleDollarSign class="h-4 w-4 shrink-0" aria-hidden="true" />
            Vendas
        </Link>
        <Link
            href="/vendas/assinaturas"
            :class="[
                'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                isAssinaturas
                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                    : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
            ]"
        >
            <Repeat class="h-4 w-4 shrink-0" aria-hidden="true" />
            Assinaturas
        </Link>
    </HorizontalScrollTabs>
</template>
