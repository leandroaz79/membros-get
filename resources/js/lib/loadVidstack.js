/** Carrega Vidstack sob demanda (aulas da área de membros) para não inflar o bundle inicial. */
let loadPromise = null;

export function ensureVidstackLoaded() {
    if (typeof window === 'undefined') {
        return Promise.resolve(false);
    }
    if (!loadPromise) {
        loadPromise = (async () => {
            await import('vidstack/player/styles/default/theme.css');
            await import('vidstack/player/styles/default/layouts/audio.css');
            await import('vidstack/player/styles/default/layouts/video.css');
            await import('vidstack/player');
            await import('vidstack/player/layouts');
            await import('vidstack/player/ui');

            return true;
        })().catch((err) => {
            loadPromise = null;
            console.warn('[Vidstack] Falha ao carregar player:', err);
            throw err;
        });
    }

    return loadPromise;
}

/** @deprecated Use ensureVidstackLoaded */
export function loadVidstack() {
    return ensureVidstackLoaded();
}
