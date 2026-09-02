<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePluginComponentResolver } from '@/composables/usePluginComponentResolver';

const props = defineProps({
    item: { type: Object, required: true },
    context: { type: Object, default: () => ({}) },
});

const page = usePage();
const pluginPagesGlob = import.meta.glob('@/PluginPages/**/*.vue');
const { resolve } = usePluginComponentResolver(computed(() => page.props.plugin_ui), pluginPagesGlob);

const component = computed(() => resolve(props.item));
</script>

<template>
    <component
        v-if="component"
        :is="component"
        v-bind="context"
    />
</template>
