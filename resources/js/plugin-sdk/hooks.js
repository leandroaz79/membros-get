import { onMounted, onUnmounted } from 'vue';

/** @type {Map<string, Set<Function>>} */
const hookCallbacks = new Map();

export function onGetfyHook(name, callback) {
    const bucket = hookCallbacks.get(name) ?? new Set();
    bucket.add(callback);
    hookCallbacks.set(name, bucket);

    return () => {
        bucket.delete(callback);
        if (bucket.size === 0) {
            hookCallbacks.delete(name);
        }
    };
}

export function doGetfyHook(name, ...args) {
    const bucket = hookCallbacks.get(name);
    if (!bucket) {
        return;
    }
    bucket.forEach((cb) => {
        try {
            cb(...args);
        } catch (e) {
            console.error(`[getfy-hook:${name}]`, e);
        }
    });
}

export function useGetfyHook(name, callback) {
    let unsubscribe = null;
    onMounted(() => {
        unsubscribe = onGetfyHook(name, callback);
    });
    onUnmounted(() => {
        unsubscribe?.();
    });
}

if (typeof window !== 'undefined') {
    window.__GETFY_HOOKS__ = {
        on: onGetfyHook,
        do: doGetfyHook,
    };
}
