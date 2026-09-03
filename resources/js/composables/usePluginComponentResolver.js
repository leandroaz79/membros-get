import { defineAsyncComponent } from 'vue';
import { resolveSlot } from '@getfy/plugin-sdk';
import { buildPluginUiIndex, resolvePluginSlotComponent } from '@/plugins/pluginUiLoader';

/**
 * Resolve componentes de plugin: runtime (dist/) ou legado (PluginPages glob).
 *
 * @param {import('vue').ComputedRef|import('vue').Ref} pluginUiRef - page.props.plugin_ui ou shared
 * @param {Record<string, () => Promise<{ default: import('vue').Component }>>} pluginPagesGlob
 */
export function usePluginComponentResolver(pluginUiRef, pluginPagesGlob) {
    const pluginUiBySlug = () => buildPluginUiIndex(pluginUiRef?.value ?? pluginUiRef ?? {});

    const cache = new Map();

    /**
     * @param {{ plugin_slug?: string, ui_mode?: string, ui_export?: string|null, component?: string }} slotItem
     */
    function resolve(slotItem) {
        if (!slotItem) {
            return null;
        }
        const cacheKey = JSON.stringify([
            slotItem.plugin_slug,
            slotItem.ui_mode,
            slotItem.ui_export,
            slotItem.component,
        ]);
        if (cache.has(cacheKey)) {
            return cache.get(cacheKey);
        }

        const runtime = resolvePluginSlotComponent(slotItem, pluginUiBySlug());
        if (runtime) {
            cache.set(cacheKey, runtime);
            return runtime;
        }

        const slotId = slotItem.slot_id ?? slotItem.id;
        if (slotId) {
            const registered = resolveSlot(slotId);
            if (registered) {
                cache.set(cacheKey, registered);
                return registered;
            }
        }

        const componentName = slotItem.component;
        if (!componentName || typeof componentName !== 'string') {
            cache.set(cacheKey, null);
            return null;
        }
        const rel = componentName.startsWith('Plugin/') ? componentName.slice(7) : componentName;
        const path = Object.keys(pluginPagesGlob).find((k) => k.endsWith(`/${rel}.vue`));
        const loader = path ? pluginPagesGlob[path] : null;
        if (!loader) {
            cache.set(cacheKey, null);
            return null;
        }
        const asyncComp = defineAsyncComponent(loader);
        cache.set(cacheKey, asyncComp);

        return asyncComp;
    }

    return { resolve };
}
