<script setup>
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

const props = defineProps({
    summary: {
        type: Object,
        default: () => ({ sent: 0, delivered: 0, failed: 0, delivery_rate: 0 }),
    },
    sparkline: {
        type: Object,
        default: () => ({ sent: [], delivered: [], failed: [] }),
    },
    loading: { type: Boolean, default: false },
});

const cards = computed(() => [
    {
        key: 'sent',
        label: 'Enviados',
        value: props.summary.sent ?? 0,
        color: '#0ea5e9',
        data: props.sparkline.sent ?? [],
    },
    {
        key: 'delivered',
        label: 'Entregues',
        value: props.summary.delivered ?? 0,
        sub: `${props.summary.delivery_rate ?? 0}%`,
        color: '#10b981',
        data: props.sparkline.delivered ?? [],
    },
    {
        key: 'failed',
        label: 'Falharam',
        value: props.summary.failed ?? 0,
        color: '#ef4444',
        data: props.sparkline.failed ?? [],
    },
]);

function sparkOptions(color, data) {
    return {
        chart: {
            type: 'area',
            sparkline: { enabled: true },
            animations: { enabled: false },
        },
        stroke: { curve: 'smooth', width: 2, colors: [color] },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 0.4,
                opacityFrom: 0.35,
                opacityTo: 0.05,
                stops: [0, 100],
            },
            colors: [color],
        },
        tooltip: { enabled: false },
        grid: { padding: { top: 4, bottom: 0, left: 0, right: 0 } },
    };
}

function sparkSeries(data) {
    return [{ data: data.length ? data : [0, 0, 0, 0] }];
}
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div
            v-for="card in cards"
            :key="card.key"
            class="relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white p-4 shadow-sm dark:border-zinc-700/80 dark:bg-zinc-800/50"
        >
            <div v-if="loading" class="space-y-2 animate-pulse">
                <div class="h-3 w-20 rounded bg-zinc-200 dark:bg-zinc-700" />
                <div class="h-8 w-12 rounded bg-zinc-200 dark:bg-zinc-700" />
            </div>
            <template v-else>
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    {{ card.label }}
                </p>
                <div class="mt-1 flex items-end justify-between gap-2">
                    <div>
                        <p class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">
                            {{ card.value }}
                        </p>
                        <p
                            v-if="card.sub"
                            class="text-xs font-medium text-emerald-600 dark:text-emerald-400"
                        >
                            {{ card.sub }}
                        </p>
                    </div>
                    <div class="h-10 w-24 shrink-0">
                        <VueApexCharts
                            type="area"
                            height="40"
                            width="96"
                            :options="sparkOptions(card.color, card.data)"
                            :series="sparkSeries(card.data)"
                        />
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
