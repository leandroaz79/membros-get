<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PluginSlotHost from '@/components/plugins/PluginSlotHost.vue';

const props = defineProps({
    zone: { type: String, required: true },
    context: { type: Object, default: () => ({}) },
    layout: { type: String, default: 'stack' },
});

const page = usePage();

const items = computed(() => {
    const zones = page.props.plugin_render_zones ?? {};
    const list = zones[props.zone];
    return Array.isArray(list) ? list : [];
});
</script>

<template>
    <PluginSlotHost
        v-if="items.length"
        :items="items"
        :context="context"
        :layout="layout"
    />
</template>
