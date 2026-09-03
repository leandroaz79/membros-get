import { defineComponent, h } from 'vue';

const MethodTile = (title, subtitle) =>
  defineComponent({
    name: title.replace(/\s+/g, '') + 'Method',
    props: {
      method: { type: Object, default: () => ({}) },
      selected: { type: Boolean, default: false },
    },
    setup(props) {
      return () =>
        h(
          'div',
          {
            class: [
              'rounded-lg border px-3 py-2 text-left text-sm transition',
              props.selected
                ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/40'
                : 'border-zinc-200 dark:border-zinc-700',
            ],
          },
          [h('div', { class: 'font-medium' }, title), h('div', { class: 'text-xs text-zinc-500' }, subtitle)],
        );
    },
  });

const PixMethod = MethodTile('PIX', 'Example Gateway');
const CardMethod = MethodTile('Cartão', 'Tokenização via plugin');
const BoletoMethod = MethodTile('Boleto', 'Example Gateway');

/**
 * Tokenizador de cartão do plugin.
 * Recebe contexto do checkout e deve retornar { payment_token, card_mask }.
 */
async function tokenizeCard(ctx = {}) {
  const number = String(ctx.cardNumber || '').replace(/\D/g, '');
  const last4 = number.slice(-4);
  const publishableKey = ctx.cardGatewayKeys?.['example-gateway']?.publishable_key || ctx.publishableKey || '';

  return {
    payment_token: JSON.stringify({
      provider: 'example-gateway',
      publishable_key: publishableKey,
      brand: 'visa',
      last4,
      exp_month: ctx.expMonth || '',
      exp_year: ctx.expYear || '',
      holder: ctx.holderName || '',
    }),
    card_mask: last4 ? `****${last4}` : '',
  };
}

window.__GETFY_PLUGIN_UI__ = window.__GETFY_PLUGIN_UI__ || {};
window.__GETFY_PLUGIN_UI__['getfy-example-gateway'] = {
  PixMethod,
  CardMethod,
  BoletoMethod,
  tokenizeCard,
};

if (typeof window.__GETFY_REGISTER_CARD_TOKENIZER__ === 'function') {
  window.__GETFY_REGISTER_CARD_TOKENIZER__('example-gateway', tokenizeCard);
}
