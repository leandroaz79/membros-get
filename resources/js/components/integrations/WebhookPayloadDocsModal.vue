<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import {
    X,
    Copy,
    Check,
    Loader2,
    Search,
    BookOpen,
    CreditCard,
    RefreshCw,
    Repeat,
} from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    catalog: {
        type: Object,
        default: () => ({ groups: [], events: [] }),
    },
    sampleWebhookUrl: { type: String, default: 'https://seu-servidor.com/webhook' },
});

const emit = defineEmits(['close', 'send-test']);

const selectedSlug = ref('pedido_pago');
const searchQuery = ref('');
const jsonTab = ref('envelope');
const loading = ref(false);
const preview = ref(null);
const copyFeedback = ref('');
const jsonPreRef = ref(null);

const groupIcons = {
    payment: CreditCard,
    recovery: RefreshCw,
    subscription: Repeat,
};

const FIELD_GUIDES = {
    pedido_pago: [
        { path: 'customer.email', hint: 'E-mail do comprador' },
        { path: 'customer.name', hint: 'Nome completo' },
        { path: 'customer.phone', hint: 'Telefone com DDI' },
        { path: 'offer.public_id', hint: 'Oferta vendida (?offer=)' },
        { path: 'checkoutUrl', hint: 'Link do checkout da oferta' },
        { path: 'amount', hint: 'Valor pago' },
        { path: 'utm_source', hint: 'UTM no topo do payload' },
    ],
    carrinho_abandonado: [
        { path: 'customer.email', hint: 'Lead que abandonou' },
        { path: 'offer.public_id', hint: 'Oferta visualizada' },
        { path: 'checkoutUrl', hint: 'Link para recuperação' },
    ],
    assinatura_criada: [
        { path: 'subscription.status', hint: 'Status da assinatura' },
        { path: 'subscription_plan.public_id', hint: 'Plano (?plan=)' },
        { path: 'customer.email', hint: 'Assinante' },
    ],
    default: [
        { path: 'event', hint: 'Slug do evento (envelope)' },
        { path: 'payload.customer.email', hint: 'Contato do comprador' },
        { path: 'payload.product.name', hint: 'Produto' },
        { path: 'payload.tracking', hint: 'UTMs e afiliado' },
    ],
};

const filteredGroups = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    const groups = props.catalog.groups || [];

    if (!q) {
        return groups;
    }

    return groups
        .map((g) => ({
            ...g,
            events: (g.events || []).filter(
                (e) =>
                    e.label?.toLowerCase().includes(q) ||
                    e.slug?.toLowerCase().includes(q) ||
                    e.description?.toLowerCase().includes(q),
            ),
        }))
        .filter((g) => g.events?.length > 0);
});

const fieldGuide = computed(() => {
    return FIELD_GUIDES[selectedSlug.value] || FIELD_GUIDES.default;
});

const envelopeJson = computed(() => {
    if (!preview.value?.envelope) {
        return '';
    }
    return JSON.stringify(preview.value.envelope, null, 2);
});

const payloadJson = computed(() => {
    if (!preview.value?.payload) {
        return '';
    }
    return JSON.stringify(preview.value.payload, null, 2);
});

const curlExample = computed(() => {
    const body = preview.value?.envelope
        ? JSON.stringify(preview.value.envelope)
        : '{}';

    return `curl -X POST '${props.sampleWebhookUrl}' \\
  -H 'Content-Type: application/json' \\
  -H 'Authorization: Bearer SEU_TOKEN_OPCIONAL' \\
  -d '${body.replace(/'/g, "'\\''")}'`;
});

async function fetchPreview() {
    loading.value = true;
    try {
        const { data } = await axios.get('/integracoes/webhooks/payload-preview', {
            params: { slug: selectedSlug.value },
        });
        preview.value = data;
    } catch {
        preview.value = null;
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.open, selectedSlug.value],
    ([isOpen]) => {
        if (isOpen) {
            fetchPreview();
        }
    },
    { immediate: true },
);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen && !selectedSlug.value) {
            selectedSlug.value = 'pedido_pago';
        }
        if (!isOpen) {
            searchQuery.value = '';
            copyFeedback.value = '';
        }
    },
);

function selectEvent(slug) {
    selectedSlug.value = slug;
}

function fallbackCopy(text) {
    try {
        const el = document.createElement('textarea');
        el.value = text;
        el.setAttribute('readonly', '');
        el.style.position = 'fixed';
        el.style.top = '0';
        el.style.left = '-9999px';
        el.style.width = '1px';
        el.style.height = '1px';
        el.style.padding = '0';
        el.style.border = 'none';
        el.style.outline = 'none';
        el.style.opacity = '0';
        document.body.appendChild(el);
        el.focus({ preventScroll: true });
        el.select();
        el.setSelectionRange(0, text.length);
        const ok = document.execCommand('copy');
        document.body.removeChild(el);
        return ok;
    } catch {
        return false;
    }
}

function showCopyFeedback(label) {
    copyFeedback.value = label;
    setTimeout(() => {
        if (copyFeedback.value === label) {
            copyFeedback.value = '';
        }
    }, 2000);
}

function copyText(text, label) {
    const s = (text ?? '').trim();
    if (!s || s.startsWith('//')) {
        return;
    }

    if (fallbackCopy(s)) {
        showCopyFeedback(label);
        return;
    }

    if (navigator.clipboard?.writeText) {
        navigator.clipboard
            .writeText(s)
            .then(() => showCopyFeedback(label))
            .catch(() => {
                if (fallbackCopy(s)) {
                    showCopyFeedback(label);
                }
            });
    }
}

function copyDisplayJson() {
    const el = jsonPreRef.value;
    let text = el ? (el.innerText || el.textContent || '').trim() : '';
    if (!text || text.startsWith('//')) {
        text = displayJson.value.trim();
    }
    copyText(text, jsonTab.value);
}

function copyField(path) {
    copyText(path, path);
}

function close() {
    emit('close');
}

const displayJson = computed(() => {
    if (jsonTab.value === 'payload') {
        return payloadJson.value;
    }
    if (jsonTab.value === 'curl') {
        return curlExample.value;
    }
    return envelopeJson.value;
});
</script>

<template>
    <Teleport to="body">
        <div
            v-show="open"
            class="fixed inset-0 z-[100001] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="webhook-payload-docs-title"
        >
            <div
                class="fixed inset-0 bg-zinc-900/60 backdrop-blur-sm dark:bg-zinc-950/70"
                @click="close"
            />
            <div
                class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-900"
            >
                <header
                    class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-200 px-5 py-4 dark:border-zinc-800"
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"
                        >
                            <BookOpen class="h-5 w-5" />
                        </div>
                        <div>
                            <h2
                                id="webhook-payload-docs-title"
                                class="text-lg font-semibold text-zinc-900 dark:text-white"
                            >
                                Documentação de payloads
                            </h2>
                            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                                Exemplos reais do corpo enviado no POST — escolha o evento e copie o JSON.
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        aria-label="Fechar"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </header>

                <div class="flex min-h-0 flex-1 flex-col lg:flex-row">
                    <aside
                        class="flex w-full shrink-0 flex-col border-b border-zinc-200 dark:border-zinc-800 lg:w-56 lg:border-b-0 lg:border-r"
                    >
                        <div class="p-3">
                            <div class="relative">
                                <Search
                                    class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400"
                                />
                                <input
                                    v-model="searchQuery"
                                    type="search"
                                    placeholder="Buscar evento..."
                                    class="w-full rounded-xl border border-zinc-200 bg-zinc-50 py-2 pl-9 pr-3 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                />
                            </div>
                        </div>
                        <nav class="flex-1 overflow-y-auto px-2 pb-4">
                            <div
                                v-for="group in filteredGroups"
                                :key="group.key"
                                class="mb-4"
                            >
                                <p
                                    class="mb-1.5 flex items-center gap-1.5 px-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-400"
                                >
                                    <component
                                        :is="groupIcons[group.key] || BookOpen"
                                        class="h-3 w-3"
                                    />
                                    {{ group.label }}
                                </p>
                                <button
                                    v-for="ev in group.events"
                                    :key="ev.slug"
                                    type="button"
                                    class="mb-1 w-full rounded-xl px-3 py-2.5 text-left text-sm transition"
                                    :class="
                                        selectedSlug === ev.slug
                                            ? 'bg-emerald-50 font-medium text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200'
                                            : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'
                                    "
                                    @click="selectEvent(ev.slug)"
                                >
                                    {{ ev.label }}
                                </button>
                            </div>
                        </nav>
                    </aside>

                    <div class="flex min-h-0 min-w-0 flex-1 flex-col">
                        <div
                            class="flex flex-wrap items-center gap-2 border-b border-zinc-200 px-4 py-2 dark:border-zinc-800"
                        >
                            <button
                                v-for="tab in [
                                    { id: 'envelope', label: 'Envelope completo' },
                                    { id: 'payload', label: 'Apenas payload' },
                                    { id: 'curl', label: 'cURL' },
                                ]"
                                :key="tab.id"
                                type="button"
                                class="rounded-lg px-3 py-1.5 text-xs font-medium transition"
                                :class="
                                    jsonTab === tab.id
                                        ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                        : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800'
                                "
                                @click="jsonTab = tab.id"
                            >
                                {{ tab.label }}
                            </button>
                            <div class="ml-auto flex items-center gap-2">
                                <span
                                    v-if="copyFeedback"
                                    class="flex items-center gap-1 text-xs text-emerald-600 dark:text-emerald-400"
                                >
                                    <Check class="h-3.5 w-3.5" />
                                    Copiado
                                </span>
                                <button
                                    type="button"
                                    class="flex items-center gap-1 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                    :disabled="loading || !displayJson"
                                    @click.stop="copyDisplayJson"
                                >
                                    <Copy class="h-3.5 w-3.5" />
                                    Copiar
                                </button>
                            </div>
                        </div>

                        <div class="relative min-h-[200px] flex-1 overflow-auto bg-zinc-950 p-4 lg:min-h-[280px]">
                            <Loader2
                                v-if="loading"
                                class="absolute left-1/2 top-1/2 h-8 w-8 -translate-x-1/2 -translate-y-1/2 animate-spin text-zinc-500"
                            />
                            <pre
                                v-else
                                ref="jsonPreRef"
                                class="font-mono text-xs leading-relaxed text-emerald-100/90"
                            >{{ displayJson || '// Selecione um evento' }}</pre>
                        </div>

                        <p
                            v-if="preview?._meta"
                            class="border-t border-zinc-200 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400"
                        >
                            PII em texto claro por padrão.
                            <span v-if="preview._meta.include_customer_hashes">
                                Hashes SHA-256 também incluídos (GETFY_WEBHOOKS_CUSTOMER_HASHES).
                            </span>
                        </p>
                    </div>

                    <aside
                        class="w-full shrink-0 border-t border-zinc-200 p-4 dark:border-zinc-800 lg:w-52 lg:border-l lg:border-t-0"
                    >
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                            Campos úteis
                        </h3>
                        <ul class="space-y-2">
                            <li
                                v-for="field in fieldGuide"
                                :key="field.path"
                                class="group"
                            >
                                <button
                                    type="button"
                                    class="w-full rounded-lg bg-zinc-50 px-2.5 py-2 text-left text-xs transition hover:bg-emerald-50 dark:bg-zinc-800/80 dark:hover:bg-emerald-900/20"
                                    @click.stop="copyField(field.path)"
                                >
                                    <code
                                        class="block truncate font-mono text-[11px] text-emerald-700 dark:text-emerald-300"
                                    >{{ field.path }}</code>
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ field.hint }}</span>
                                </button>
                            </li>
                        </ul>
                        <button
                            type="button"
                            class="mt-4 w-full rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                            @click="emit('send-test'); close()"
                        >
                            Enviar teste real
                        </button>
                    </aside>
                </div>
            </div>
        </div>
    </Teleport>
</template>
