/** Mensagem postMessage enviada ao site pai para ajustar a altura do iframe. */
export const CHECKOUT_EMBED_RESIZE_MESSAGE = 'getfy-checkout-embed-resize';

export function isRunningInIframe() {
    if (typeof window === 'undefined') {
        return false;
    }
    try {
        return window.self !== window.top;
    } catch {
        return true;
    }
}

export function postEmbedHeight(height) {
    if (!isRunningInIframe() || typeof window.parent?.postMessage !== 'function') {
        return;
    }
    window.parent.postMessage(
        {
            type: CHECKOUT_EMBED_RESIZE_MESSAGE,
            height: Math.max(320, Math.ceil(Number(height) || 0)),
        },
        '*'
    );
}

/**
 * Observa o documento e envia a altura ao site pai (auto-resize do iframe).
 *
 * @param {HTMLElement|null|undefined} rootEl
 * @returns {() => void}
 */
export function startCheckoutEmbedResize(rootEl) {
    if (typeof window === 'undefined' || !rootEl || !isRunningInIframe()) {
        return () => {};
    }

    const notify = () => {
        const height = Math.max(
            document.documentElement.scrollHeight,
            document.body?.scrollHeight ?? 0,
            rootEl.scrollHeight ?? 0
        );
        postEmbedHeight(height);
    };

    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(notify) : null;
    ro?.observe(rootEl);
    ro?.observe(document.documentElement);

    const mo = typeof MutationObserver !== 'undefined'
        ? new MutationObserver(notify)
        : null;
    mo?.observe(document.body, { childList: true, subtree: true, attributes: true });

    window.addEventListener('load', notify);
    notify();

    return () => {
        ro?.disconnect();
        mo?.disconnect();
        window.removeEventListener('load', notify);
    };
}

/**
 * Snippet HTML para colar no site externo (iframe + auto-resize).
 *
 * @param {string} checkoutUrl
 * @param {number} [minHeight=720]
 */
export function buildCheckoutEmbedSnippet(checkoutUrl, minHeight = 720) {
    const safeUrl = String(checkoutUrl || '').replace(/"/g, '&quot;');
    return `<!-- Getfy Checkout -->
<iframe
  data-getfy-checkout
  src="${safeUrl}"
  title="Checkout"
  style="width:100%;min-height:${minHeight}px;border:0;display:block;"
  allow="payment *"
  loading="lazy"
></iframe>
<script>
window.addEventListener('message', function (event) {
  if (!event.data || event.data.type !== '${CHECKOUT_EMBED_RESIZE_MESSAGE}') return;
  var height = event.data.height;
  if (!height) return;
  document.querySelectorAll('iframe[data-getfy-checkout]').forEach(function (frame) {
    frame.style.height = height + 'px';
  });
});
</script>`;
}
