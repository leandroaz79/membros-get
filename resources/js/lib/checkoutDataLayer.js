/**
 * Ponte dataLayer para GTM / GA4 ecommerce — eventos padronizados no checkout.
 */

export function ensureDataLayer() {
    if (typeof window === 'undefined') {
        return [];
    }
    window.dataLayer = window.dataLayer || [];
    return window.dataLayer;
}

export function pushDataLayerEvent(event, payload = {}) {
    if (typeof window === 'undefined') {
        return;
    }
    ensureDataLayer().push({
        event,
        ...payload,
    });
}

function normalizeCurrency(currency) {
    return typeof currency === 'string' && currency.trim()
        ? currency.trim().toUpperCase()
        : 'BRL';
}

function normalizeItems(items) {
    if (!Array.isArray(items)) {
        return [];
    }
    return items
        .filter((item) => item && String(item.item_id ?? item.id ?? '').trim() !== '')
        .map((item) => ({
            item_id: String(item.item_id ?? item.id).trim(),
            item_name: String(item.item_name ?? item.name ?? '').trim() || undefined,
            price: Number(item.price ?? item.item_price) || 0,
            quantity: Math.max(1, parseInt(item.quantity, 10) || 1),
        }));
}

/**
 * @param {object} extra
 * @param {string} [extra.page_path]
 * @param {string} [extra.checkout_slug]
 * @param {string} [extra.product_name]
 * @param {number} [extra.value]
 * @param {string} [extra.currency]
 */
export function pushPageView(extra = {}) {
    const value = Number(extra.value) || 0;
    const currency = normalizeCurrency(extra.currency);
    pushDataLayerEvent('page_view', {
        page_path: extra.page_path ?? (typeof location !== 'undefined' ? location.pathname : undefined),
        checkout_slug: extra.checkout_slug,
        product_name: extra.product_name,
    });
    if (value > 0) {
        pushDataLayerEvent('view_item', {
            value,
            currency,
            items: normalizeItems(extra.items),
        });
    }
}

export function pushBeginCheckout(extra = {}) {
    const value = Number(extra.value) || 0;
    const currency = normalizeCurrency(extra.currency);
    pushDataLayerEvent('begin_checkout', {
        value,
        currency,
        items: normalizeItems(extra.items),
        checkout_slug: extra.checkout_slug,
    });
}

export function pushPurchase(extra = {}) {
    const value = Number(extra.value) || 0;
    const currency = normalizeCurrency(extra.currency);
    const transactionId = extra.transaction_id ?? extra.order_id ?? '';
    pushDataLayerEvent('purchase', {
        value,
        currency,
        transaction_id: transactionId ? String(transactionId) : undefined,
        items: normalizeItems(extra.items ?? extra.purchase_contents),
        payment_type: extra.payment_type,
        trigger_type: extra.trigger_type ?? 'approved',
    });
}

/**
 * PIX ou boleto gerado — GTM pode mapear para add_payment_info ou evento customizado.
 */
export function pushPaymentGenerated(extra = {}) {
    const value = Number(extra.value) || 0;
    const currency = normalizeCurrency(extra.currency);
    const method = extra.payment_method === 'boleto' ? 'boleto' : 'pix';
    pushDataLayerEvent('add_payment_info', {
        value,
        currency,
        payment_type: method,
        transaction_id: extra.order_id ? String(extra.order_id) : undefined,
        checkout_slug: extra.checkout_slug,
    });
    pushDataLayerEvent(method === 'boleto' ? 'boleto_generated' : 'pix_generated', {
        value,
        currency,
        order_id: extra.order_id,
        checkout_slug: extra.checkout_slug,
    });
}
