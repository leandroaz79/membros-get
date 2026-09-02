<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePluginComponentResolver } from '@/composables/usePluginComponentResolver';

const props = defineProps({
    items: { type: Array, default: () => [] },
    context: { type: Object, default: () => ({}) },
    /** stack | tabs | grid */
    layout: { type: String, default: 'stack' },
    /** id do item ativo (layout tabs) */
    activeId: { type: String, default: '' },
});

const emit = defineEmits(['update:activeId']);

const page = usePage();
const pluginPagesGlob = import.meta.glob('@/PluginPages/**/*.vue');
const { resolve } = usePluginComponentResolver(computed(() => page.props.plugin_ui), pluginPagesGlob);

const enriched = computed(() =>
    (props.items || [])
        .map((item) => {
            const component = resolve(item);
            if (!component) {
                return null;
            }
            return {
                ...item,
                component,
                key: item.id || `${item.plugin_slug}-${item.ui_export || item.label}`,
            };
        })
        .filter(Boolean),
);

const activeTabId = computed({
    get() {
        if (props.activeId) {
            return props.activeId;
        }
        return enriched.value[0]?.key ?? '';
    },
    set(value) {
        emit('update:activeId', value);
    },
});

const activeItem = computed(() =>
    enriched.value.find((i) => i.key === activeTabId.value) ?? enriched.value[0] ?? null,
);
</script>

<template>
    <div v-if="enriched.length" class="plugin-slot-host">
        <nav
            v-if="layout === 'tabs' && enriched.length > 1"
            class="mb-4 flex flex-wrap gap-1 rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-900/50"
        >
            <button
                v-for="item in enriched"
                :key="item.key"
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium transition"
                :class="activeTabId === item.key
                    ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white'
                    : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                @click="activeTabId = item.key"
            >
                {{ item.label || item.id }}
            </button>
        </nav>

        <div
            v-if="layout === 'grid'"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div
                v-for="item in enriched"
                :key="item.key"
                class="panel-card min-h-0 p-4"
            >
                <component :is="item.component" v-bind="{ ...context, ...item.props }" />
            </div>
        </div>

        <template v-else-if="layout === 'tabs'">
            <component
                v-if="activeItem"
                :is="activeItem.component"
                :key="activeItem.key"
                v-bind="{ ...context, ...activeItem.props }"
            />
        </template>

        <template v-else>
            <div
                v-for="item in enriched"
                :key="item.key"
                class="mb-4 last:mb-0"
            >
                <component :is="item.component" v-bind="{ ...context, ...item.props }" />
            </div>
        </template>
    </div>
</template>
