/** @type {Map<string, { url: string, type: 'style' | 'script' }>} */
const enqueued = new Map();

export function enqueueStyle(handle, url) {
    if (!handle || !url || enqueued.has(handle)) {
        return;
    }
    enqueued.set(handle, { url, type: 'style' });
    if (typeof document !== 'undefined') {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        link.dataset.getfyAsset = handle;
        document.head.appendChild(link);
    }
}

export function enqueueScript(handle, url, { defer = true } = {}) {
    if (!handle || !url || enqueued.has(handle)) {
        return;
    }
    enqueued.set(handle, { url, type: 'script' });
    if (typeof document !== 'undefined') {
        const script = document.createElement('script');
        script.src = url;
        if (defer) {
            script.defer = true;
        }
        script.dataset.getfyAsset = handle;
        document.head.appendChild(script);
    }
}

export function flushEnqueuedAssets() {
    return Array.from(enqueued.entries()).map(([handle, meta]) => ({ handle, ...meta }));
}
