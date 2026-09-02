/** @type {Map<string, import('vue').Component>} */
const slotRegistry = new Map();

/**
 * Registra componente em slot genérico (alternativa ao manifest).
 *
 * @param {string} slotId  ex: vendas.order_detail
 * @param {import('vue').Component} component
 */
export function registerSlot(slotId, component) {
    if (!slotId || !component) {
        return;
    }
    slotRegistry.set(slotId, component);
    if (typeof window !== 'undefined') {
        window.__GETFY_PLUGIN_SLOTS__ = window.__GETFY_PLUGIN_SLOTS__ || {};
        window.__GETFY_PLUGIN_SLOTS__[slotId] = component;
    }
}

export function resolveSlot(slotId) {
    return slotRegistry.get(slotId) ?? window.__GETFY_PLUGIN_SLOTS__?.[slotId] ?? null;
}

export function getRegisteredSlots() {
    return Array.from(slotRegistry.keys());
}
