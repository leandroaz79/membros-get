import { registerGatewayMethod } from '@/components/checkout/gateways/registry';
import { buildPluginUiIndex, ensurePluginUiLoaded } from '@/plugins/pluginUiLoader';

/**
 * @param {string} gatewaySlug
 * @param {(ctx: Record<string, unknown>) => Promise<{ payment_token: string, card_mask?: string }>} tokenizer
 */
export function registerPluginCardTokenizer(gatewaySlug, tokenizer) {
    const slug = (gatewaySlug || '').toLowerCase();
    if (!slug || typeof tokenizer !== 'function' || typeof window === 'undefined') {
        return;
    }
    window.__GETFY_PLUGIN_CARD_TOKENIZERS__ = window.__GETFY_PLUGIN_CARD_TOKENIZERS__ || {};
    window.__GETFY_PLUGIN_CARD_TOKENIZERS__[slug] = tokenizer;
}

/**
 * Registra componentes de checkout exportados por plugins (frontend.exports.checkout).
 *
 * @param {Record<string, unknown>} pluginUiPayload
 * @param {Array<{ gateway_slug?: string, id?: string }>} paymentMethods
 */
export async function registerPluginCheckoutComponents(pluginUiPayload, paymentMethods = []) {
    const bySlug = buildPluginUiIndex(pluginUiPayload);
    const methods = Array.isArray(paymentMethods) ? paymentMethods : [];

    if (typeof window !== 'undefined') {
        window.__GETFY_REGISTER_CARD_TOKENIZER__ = registerPluginCardTokenizer;
    }

    for (const meta of Object.values(bySlug)) {
        const checkoutMap = meta?.frontend_exports_map?.checkout;
        if (!meta.entry) {
            continue;
        }

        const declaredGateway = (meta.checkout_gateway_slug || '').toLowerCase();
        const slugsUsed = new Set(
            methods
                .map((m) => (m?.gateway_slug || '').toLowerCase())
                .filter(Boolean),
        );

        let gatewaySlug = declaredGateway || meta.slug;
        if (!declaredGateway) {
            for (const s of slugsUsed) {
                if (s.includes(meta.slug) || meta.slug.includes(s)) {
                    gatewaySlug = s;
                    break;
                }
            }
        }

        if (checkoutMap && typeof checkoutMap === 'object') {
            for (const [methodId, exportName] of Object.entries(checkoutMap)) {
                if (!exportName || typeof exportName !== 'string') {
                    continue;
                }
                if (methodId === 'gateway_slug') {
                    continue;
                }
                try {
                    await ensurePluginUiLoaded(meta, exportName);
                    const component = window.__GETFY_PLUGIN_UI__?.[meta.slug]?.[exportName];
                    if (component) {
                        registerGatewayMethod(gatewaySlug, methodId, component);
                    }
                } catch (_) {
                    // Plugin checkout UI opcional; falha não bloqueia checkout.
                }
            }
        }

        const tokenizeExport = meta?.frontend_exports_map?.tokenizeCard;
        if (typeof tokenizeExport === 'string' && tokenizeExport !== '') {
            try {
                await ensurePluginUiLoaded(meta, tokenizeExport);
                const fn = window.__GETFY_PLUGIN_UI__?.[meta.slug]?.[tokenizeExport];
                if (typeof fn === 'function') {
                    registerPluginCardTokenizer(gatewaySlug, fn);
                }
            } catch (_) {
                // Tokenização opcional.
            }
        }
    }
}

if (typeof window !== 'undefined') {
    window.__GETFY_CHECKOUT_REGISTER__ = registerGatewayMethod;
    window.__GETFY_REGISTER_CARD_TOKENIZER__ = registerPluginCardTokenizer;
}
