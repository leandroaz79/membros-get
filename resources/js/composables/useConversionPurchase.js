import {
    pixelsNeedGoogle,
    pixelsNeedMeta,
    pixelsNeedTiktok,
} from '@/lib/pixelPlatforms';

const PURCHASE_FIRED_PREFIX = 'getfy_purchase_fired_';

export function purchaseFiredStorageKey(orderId) {
    return `${PURCHASE_FIRED_PREFIX}${orderId}`;
}

export function wasPurchaseFired(orderId) {
    if (!orderId || typeof sessionStorage === 'undefined') return false;
    try {
        return sessionStorage.getItem(purchaseFiredStorageKey(orderId)) === '1';
    } catch {
        return false;
    }
}

export function markPurchaseFired(orderId) {
    if (!orderId || typeof sessionStorage === 'undefined') return;
    try {
        sessionStorage.setItem(purchaseFiredStorageKey(orderId), '1');
    } catch {
        /* ignore */
    }
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function metaPixelReady() {
    return typeof window !== 'undefined' && typeof window.fbq === 'function';
}

function gtagReady() {
    return typeof window !== 'undefined' && typeof window.gtag === 'function';
}

function tiktokReady() {
    return typeof window !== 'undefined' && typeof window.ttq?.track === 'function';
}

/**
 * Aguarda SDKs necessários conforme pixels configurados.
 *
 * @param {object|null} pixels
 * @param {number} maxWaitMs
 */
export async function waitForPixelSdks(pixels, maxWaitMs = 3000) {
    const needMeta = pixelsNeedMeta(pixels);
    const needGoogle = pixelsNeedGoogle(pixels);
    const needTiktok = pixelsNeedTiktok(pixels);
    const started = Date.now();

    while (Date.now() - started < maxWaitMs) {
        const metaOk = !needMeta || metaPixelReady();
        const googleOk = !needGoogle || gtagReady();
        const tiktokOk = !needTiktok || tiktokReady();
        if (metaOk && googleOk && tiktokOk) {
            return;
        }
        await sleep(80);
    }
}

/**
 * Dispara Purchase no browser quando os pixels estiverem prontos.
 *
 * @param {object|null} pixelsApi - expose de ConversionPixels (firePurchase)
 * @param {object} payload - { order_id, amount, currency, meta_event_id, purchase_contents }
 * @param {object} options - { maxWaitMs, skipDedup, triggerType, pixels }
 * @returns {Promise<boolean>} true se disparou (ou já estava deduplicado)
 */
export async function firePurchaseWhenReady(pixelsApi, payload, options = {}) {
    const orderId = payload?.order_id;
    if (!orderId || !pixelsApi?.firePurchase) {
        return false;
    }

    const maxWaitMs = Math.max(500, Number(options.maxWaitMs) || 3000);
    const skipDedup = !!options.skipDedup;
    const triggerType = options.triggerType || 'approved';
    const pixels = options.pixels ?? null;

    if (!skipDedup && wasPurchaseFired(orderId)) {
        return true;
    }

    await waitForPixelSdks(pixels, maxWaitMs);

    if (!skipDedup && wasPurchaseFired(orderId)) {
        return true;
    }

    const amount = Number(payload.amount) || 0;
    const currency =
        typeof payload.currency === 'string' && payload.currency.trim()
            ? payload.currency.trim().toUpperCase()
            : 'BRL';
    const metaEventId =
        typeof payload.meta_event_id === 'string' && payload.meta_event_id.trim()
            ? payload.meta_event_id.trim()
            : `getfy_purchase_${orderId}`;
    const contents = Array.isArray(payload.purchase_contents) ? payload.purchase_contents : [];

    pixelsApi.firePurchase(amount, currency, String(orderId), false, triggerType, {
        eventId: metaEventId,
        contents,
    });

    if (!skipDedup) {
        markPurchaseFired(orderId);
    }

    return true;
}

/**
 * Aguarda Purchase disparar (ou dedup) antes de redirecionar.
 */
export async function redirectAfterPurchaseReady(pixelsApi, payload, redirectFn, options = {}) {
    await firePurchaseWhenReady(pixelsApi, payload, options);
    const delayMs = Math.max(0, Number(options.redirectDelayMs) ?? 450);
    if (delayMs > 0) {
        await sleep(delayMs);
    }
    if (typeof redirectFn === 'function') {
        redirectFn();
    }
}
