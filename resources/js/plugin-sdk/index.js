/**
 * @getfy/plugin-sdk — SDK JavaScript para bundles de plugins.
 */
export { useGetfyHook, doGetfyHook, onGetfyHook } from './hooks';
export { registerSlot, resolveSlot, getRegisteredSlots } from './slots';
export { enqueueStyle, enqueueScript, flushEnqueuedAssets } from './assets';
