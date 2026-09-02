/**
 * Normaliza URLs de imagens do checkout para /storage/... relativo ao host atual.
 * Corrige URLs absolutas salvas com APP_URL antigo (ngrok, domínio trocado, etc.).
 */
export function normalizeCheckoutImageUrl(url) {
    if (url == null || typeof url !== 'string') {
        return '';
    }

    const trimmed = url.trim();
    if (trimmed === '') {
        return '';
    }

    if (trimmed.startsWith('/storage/')) {
        return trimmed;
    }

    if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
        try {
            const parsed = new URL(trimmed);
            if (parsed.pathname.startsWith('/storage/')) {
                return parsed.pathname + parsed.search;
            }
        } catch {
            /* ignore */
        }
    }

    return trimmed;
}
