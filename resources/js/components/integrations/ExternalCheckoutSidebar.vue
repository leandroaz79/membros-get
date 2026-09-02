<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { X, Plus, ArrowLeft, ArrowDownToLine } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    endpoints: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

/** Payload de exemplo (estrutura típica checkout externo com `data` + `customer`). */
const EXAMPLE_PAYLOAD = {
    event: 'purchase_approved',
    data: {
        id: '67597a04-90a2-453e-b1b4-e03034a38473',
        status: 'paid',
        refId: '095YCL1',
        amount: 90,
        customer: {
            name: 'John Doe',
            email: 'john.doe@example.com',
            phone: '34999999999',
            docType: 'cpf',
            docNumber: '12345678909',
        },
        product: {
            id: 'ff3fdf61-e88f-43b5-982a-32d50f112414',
            name: 'Produto Teste',
        },
        paidAt: '2026-05-04T20:56:22.554286+00:00',
        paymentMethod: 'credit_card',
    },
};

/** Mapeamento recomendado para o payload de exemplo acima. */
const EXAMPLE_FIELD_MAP = {
    _strict: true,
    email: ['data.customer.email'],
    name: ['data.customer.name'],
    cpf: ['data.customer.docNumber'],
    phone: ['data.customer.phone'],
    external_id: ['data.id', 'data.refId'],
};

const examplePayloadJson = computed(() => JSON.stringify(EXAMPLE_PAYLOAD, null, 2));
const exampleFieldMapJson = computed(() => JSON.stringify(EXAMPLE_FIELD_MAP, null, 2));

const base = '/integracoes/checkout-externo';

const loading = ref(false);
const saving = ref(false);
const deleting = ref(null);
const regenerating = ref(null);
const error = ref('');
const ok = ref('');

/** URL completa exibida após criar endpoint ou regenerar token. */
const revealedUrlBanner = ref(null);

const localEndpoints = ref([]);
const products = ref([]);

const editingEndpoint = ref(null);
const isCreating = ref(false);

const showHelper = ref(false);
const helperTab = ref('example');
const aiPayloadInput = ref('');

const defaultFieldMapJson = () =>
    JSON.stringify(
        {
            _strict: false,
            email: ['data.customer.email', 'customer.email', 'email'],
            name: ['data.customer.name', 'customer.name', 'name'],
            cpf: ['data.customer.docNumber', 'data.customer.cpf', 'cpf'],
            phone: ['data.customer.phone', 'phone'],
            external_id: ['data.id', 'data.refId', 'external_id'],
        },
        null,
        2
    );

const form = ref({
    name: '',
    product_id: '',
    product_offer_id: null,
    subscription_plan_id: null,
    is_active: true,
    signing_secret: '',
    field_map_json: defaultFieldMapJson(),
});

const showingForm = computed(
    () => editingEndpoint.value !== null || isCreating.value
);

const currentView = computed(() => (showingForm.value ? 'form' : 'hub'));

const headerTitle = computed(() => {
    if (currentView.value === 'form') {
        return editingEndpoint.value ? 'Editar endpoint' : 'Novo endpoint';
    }
    return 'Checkout externo';
});

const selectedProduct = computed(
    () => products.value.find((p) => p.id === form.value.product_id) || null
);

/**
 * Prompt em PT-BR para colar no ChatGPT e obter um `field_map` adaptado ao payload.
 */
function buildAiPrompt(payloadText) {
    const trimmed = payloadText.trim();
    let prettyPayload = trimmed;
    try {
        prettyPayload = JSON.stringify(JSON.parse(trimmed), null, 2);
    } catch {
        // mantém texto bruto no bloco
    }
    const referenceJson = JSON.stringify(EXAMPLE_FIELD_MAP, null, 2);

    return `Você é um assistente que mapeia webhooks de checkout (JSON) para o formato \`field_map\` do **Checkout externo Getfy**.

## Regras do field_map
- Chaves permitidas (e só estas): \`email\`, \`name\`, \`cpf\`, \`phone\`, \`external_id\`, e opcionalmente \`_strict\` (boolean).
- Cada chave pode ser **uma string** (um caminho em dot notation, ex.: \`data.customer.email\`) ou **um array de strings** — vários caminhos pela **ordem de tentativa** até encontrar valor válido.
- \`_strict: true\` significa: usar **apenas** os caminhos que você indicar (sem sugestões automáticas do sistema). \`_strict: false\` ou omitir permite fallbacks internos depois dos seus caminhos.
- **email** é obrigatório no resultado (caminho(s) que levem a um e-mail válido no payload).
- **external_id** é opcional mas recomendado para idempotência (ex.: id do pedido na plataforma de origem).
- Use **apenas** caminhos que existam no payload de exemplo. Não invente chaves além das listadas.

## Tarefa
Analise o JSON abaixo (webhook real ou de teste da plataforma) e devolva **APENAS** um objeto JSON válido — o \`field_map\` — sem markdown, sem comentários, sem texto antes ou depois.

## Payload de exemplo da plataforma (entrada)
\`\`\`json
${prettyPayload}
\`\`\`

## Exemplo de saída válida (referência de formato — adapta os caminhos ao payload acima, não copies à cega se a estrutura for diferente)
\`\`\`json
${referenceJson}
\`\`\`
`;
}

const aiGeneratedPrompt = computed(() => {
    const t = aiPayloadInput.value.trim();
    if (!t) {
        return 'Cole o JSON do payload na caixa acima. Quando for JSON válido, o prompt completo aparece aqui automaticamente — podes copiar e colar no ChatGPT.';
    }
    try {
        JSON.parse(t);
        return buildAiPrompt(t);
    } catch {
        return 'JSON inválido no payload. Corrija a sintaxe acima; quando o JSON for válido, o prompt completo aparecerá aqui.';
    }
});

watch(
    () => props.endpoints,
    (list) => {
        if (!props.open) {
            localEndpoints.value = [...(list || [])];
        }
    },
    { immediate: true, deep: true }
);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            loadAll();
        } else {
            resetForm();
            error.value = '';
            ok.value = '';
            revealedUrlBanner.value = null;
        }
    }
);

function resetHelperState() {
    showHelper.value = false;
    helperTab.value = 'example';
    aiPayloadInput.value = '';
}

function mergeEndpointRow(row) {
    if (!row?.id) {
        return;
    }
    const idx = localEndpoints.value.findIndex((e) => e.id === row.id);
    if (idx >= 0) {
        localEndpoints.value[idx] = { ...localEndpoints.value[idx], ...row };
    } else {
        localEndpoints.value.unshift(row);
    }
}

function resetForm() {
    editingEndpoint.value = null;
    isCreating.value = false;
    form.value = {
        name: '',
        product_id: '',
        product_offer_id: null,
        subscription_plan_id: null,
        is_active: true,
        signing_secret: '',
        field_map_json: defaultFieldMapJson(),
    };
    resetHelperState();
    error.value = '';
}

function applyExampleFieldMap() {
    form.value.field_map_json = JSON.stringify(EXAMPLE_FIELD_MAP, null, 2);
    ok.value = 'Mapeamento de exemplo aplicado no campo acima.';
}

function toggleHelper() {
    showHelper.value = !showHelper.value;
    if (showHelper.value) {
        helperTab.value = 'example';
    }
}

function openChatGpt() {
    window.open('https://chat.openai.com/', '_blank', 'noopener,noreferrer');
}

async function loadAll() {
    loading.value = true;
    error.value = '';
    try {
        const [epRes, prRes] = await Promise.all([
            axios.get(`${base}/endpoints`),
            axios.get(`${base}/products`),
        ]);
        localEndpoints.value = epRes.data?.data || [];
        products.value = prRes.data?.data || [];
    } catch (e) {
        error.value = e.response?.data?.message || 'Não foi possível carregar os endpoints.';
    } finally {
        loading.value = false;
    }
}

function startNew() {
    editingEndpoint.value = null;
    isCreating.value = true;
    form.value = {
        name: '',
        product_id: products.value[0]?.id || '',
        product_offer_id: null,
        subscription_plan_id: null,
        is_active: true,
        signing_secret: '',
        field_map_json: defaultFieldMapJson(),
    };
    resetHelperState();
    error.value = '';
    ok.value = '';
}

function openEdit(row) {
    isCreating.value = false;
    editingEndpoint.value = row;
    form.value = {
        name: row.name,
        product_id: row.product_id,
        product_offer_id: row.product_offer_id,
        subscription_plan_id: row.subscription_plan_id,
        is_active: row.is_active,
        signing_secret: '',
        field_map_json: JSON.stringify(
            row.field_map && Object.keys(row.field_map).length
                ? row.field_map
                : {
                      _strict: false,
                      email: ['data.customer.email', 'email'],
                      name: ['data.customer.name', 'name'],
                      cpf: ['data.customer.docNumber', 'cpf'],
                      phone: ['data.customer.phone', 'phone'],
                      external_id: ['data.id', 'external_id'],
                  },
            null,
            2
        ),
    };
    resetHelperState();
    error.value = '';
    ok.value = '';
}

function cancelEdit() {
    resetForm();
}

function parseFieldMap() {
    try {
        const raw = JSON.parse(form.value.field_map_json);
        if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null;
        return raw;
    } catch {
        return null;
    }
}

async function save() {
    const fieldMap = parseFieldMap();
    if (!fieldMap) {
        error.value = 'JSON de mapeamento de campos inválido.';
        return;
    }
    saving.value = true;
    error.value = '';
    ok.value = '';
    try {
        const payload = {
            name: form.value.name,
            product_id: form.value.product_id,
            product_offer_id: form.value.product_offer_id || null,
            subscription_plan_id: form.value.subscription_plan_id || null,
            is_active: form.value.is_active,
            field_map: fieldMap,
        };
        if (form.value.signing_secret?.trim()) {
            payload.signing_secret = form.value.signing_secret.trim();
        }
        if (editingEndpoint.value) {
            await axios.put(`${base}/endpoints/${editingEndpoint.value.id}`, payload);
            ok.value = 'Endpoint atualizado.';
            resetForm();
        } else {
            const { data } = await axios.post(`${base}/endpoints`, payload);
            mergeEndpointRow(data);
            revealedUrlBanner.value = {
                id: data.id,
                url: data.url,
                name: data.name,
                reason: 'created',
            };
            ok.value = 'Endpoint criado. Copie a URL abaixo.';
            resetForm();
        }
        await loadAll();
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.message || 'Erro ao salvar.';
    } finally {
        saving.value = false;
    }
}

async function remove(id) {
    if (!confirm('Remover este endpoint? A URL deixará de funcionar.')) return;
    deleting.value = id;
    error.value = '';
    try {
        await axios.delete(`${base}/endpoints/${id}`);
        ok.value = 'Removido.';
        if (editingEndpoint.value?.id === id) {
            resetForm();
        }
        await loadAll();
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.message || 'Erro ao remover.';
    } finally {
        deleting.value = null;
    }
}

async function regenerate(id) {
    if (!confirm('Gerar novo token? A URL antiga para de funcionar.')) return;
    regenerating.value = id;
    error.value = '';
    try {
        const { data } = await axios.post(`${base}/endpoints/${id}/regenerate-token`);
        mergeEndpointRow(data);
        revealedUrlBanner.value = {
            id: data.id,
            url: data.url,
            name: data.name,
            reason: 'regenerated',
        };
        ok.value = 'Novo token gerado. Copie a nova URL abaixo.';
        await loadAll();
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.message || 'Erro ao regenerar.';
    } finally {
        regenerating.value = null;
    }
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
        const copied = document.execCommand('copy');
        document.body.removeChild(el);
        return copied;
    } catch {
        return false;
    }
}

async function copyText(text) {
    const value = String(text || '').trim();
    if (!value) {
        error.value = 'Nada para copiar.';
        return;
    }

    if (fallbackCopy(value)) {
        ok.value = 'Copiado.';
        error.value = '';
        return;
    }

    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(value);
            ok.value = 'Copiado.';
            error.value = '';
            return;
        } catch {
            // tenta fallback novamente
            if (fallbackCopy(value)) {
                ok.value = 'Copiado.';
                error.value = '';
                return;
            }
        }
    }

    error.value = 'Não foi possível copiar. Selecione o texto e copie manualmente.';
}

async function copyAiPrompt() {
    await copyText(aiGeneratedPrompt.value);
}

function close() {
    emit('close');
}

function truncateUrl(url, max = 52) {
    if (!url) return '';
    if (url.length <= max) return url;
    return url.slice(0, max) + '…';
}
</script>

<template>
    <Teleport to="body">
        <div
            v-show="open"
            class="fixed inset-0 z-[100000] flex justify-end"
            aria-modal="true"
            role="dialog"
        >
            <div
                class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/60"
                aria-hidden="true"
                @click="close"
            />
            <aside
                class="relative flex h-full w-full max-w-4xl flex-col rounded-l-2xl bg-white shadow-2xl dark:bg-zinc-900"
            >
                <div
                    class="flex items-center justify-between gap-3 rounded-tl-2xl bg-zinc-50/80 px-5 py-4 dark:bg-zinc-800/50"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <button
                            v-if="currentView === 'form'"
                            type="button"
                            class="shrink-0 rounded-lg p-2 text-zinc-500 hover:bg-zinc-200/80 dark:hover:bg-zinc-700"
                            title="Voltar"
                            @click="cancelEdit"
                        >
                            <ArrowLeft class="h-5 w-5" />
                        </button>
                        <div class="min-w-0">
                            <h2 class="truncate text-lg font-semibold text-zinc-900 dark:text-white">
                                {{ headerTitle }}
                            </h2>
                            <p
                                v-if="currentView === 'hub'"
                                class="flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                <ArrowDownToLine class="h-3 w-3" />
                                Plataformas externas enviam dados para o Getfy
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg p-2 text-zinc-500 hover:bg-zinc-200/80 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                        aria-label="Fechar"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex flex-1 flex-col overflow-y-auto">
                    <!-- Hub -->
                    <template v-if="currentView === 'hub'">
                        <div class="space-y-4 p-4">
                            <div class="space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                                <p>
                                    Diferente do <strong class="font-medium text-zinc-800 dark:text-zinc-200">Webhook de saída</strong>
                                    (o Getfy envia eventos <em>para</em> uma URL externa), o
                                    <strong class="font-medium text-zinc-800 dark:text-zinc-200">Checkout externo</strong>
                                    recebe vendas de outras plataformas: elas fazem
                                    <code class="rounded bg-zinc-100 px-1 text-xs dark:bg-zinc-800">POST</code>
                                    <em>para</em> o Getfy em
                                    <code class="rounded bg-zinc-100 px-1 text-xs dark:bg-zinc-800">POST …/webhooks/inbound/&lt;token&gt;</code>.
                                </p>
                                <p>
                                    Ao processar o webhook, o Getfy cria um pedido concluído, vincula o aluno à área de membros
                                    e envia o e-mail de acesso — o mesmo fluxo de uma venda aprovada no checkout nativo.
                                </p>
                                <ul class="list-inside list-disc text-xs">
                                    <li>
                                        Campo obrigatório no JSON:
                                        <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">email</code>
                                        (ou caminho configurado no mapeamento).
                                    </li>
                                    <li>
                                        Opcional:
                                        <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">external_id</code>
                                        para idempotência (mesmo valor não cria pedido duplicado).
                                    </li>
                                    <li>
                                        Assinatura (opcional): cabeçalho
                                        <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">X-Webhook-Signature: sha256=&lt;hmac_hex&gt;</code>
                                        com o corpo bruto em HMAC-SHA256.
                                    </li>
                                </ul>
                            </div>

                            <div v-if="loading" class="text-sm text-zinc-500 dark:text-zinc-400">
                                Carregando…
                            </div>

                            <div
                                v-if="error"
                                class="rounded-lg bg-red-100 px-3 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300"
                            >
                                {{ error }}
                            </div>
                            <div
                                v-if="ok"
                                class="rounded-lg bg-emerald-100 px-3 py-2 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200"
                            >
                                {{ ok }}
                            </div>

                            <div
                                v-if="revealedUrlBanner"
                                class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/40"
                            >
                                <p class="text-sm font-medium text-emerald-900 dark:text-emerald-200">
                                    {{
                                        revealedUrlBanner.reason === 'regenerated'
                                            ? 'Nova URL do endpoint'
                                            : 'URL do endpoint criado'
                                    }}
                                    <span v-if="revealedUrlBanner.name" class="font-normal text-emerald-800/80 dark:text-emerald-300/80">
                                        — {{ revealedUrlBanner.name }}
                                    </span>
                                </p>
                                <p class="mt-1 text-xs text-emerald-800/80 dark:text-emerald-300/80">
                                    Use esta URL na plataforma externa. Guarde-a em local seguro — o token aparece por completo aqui.
                                </p>
                                <div
                                    class="mt-3 break-all rounded-lg border border-emerald-200 bg-white px-3 py-2 font-mono text-xs text-zinc-800 dark:border-emerald-900 dark:bg-zinc-900 dark:text-zinc-100"
                                >
                                    {{ revealedUrlBanner.url }}
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        class="bg-emerald-600 hover:bg-emerald-700"
                                        @click="copyText(revealedUrlBanner.url)"
                                    >
                                        Copiar URL completa
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        @click="revealedUrlBanner = null"
                                    >
                                        Ocultar
                                    </Button>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <Button class="bg-emerald-600 hover:bg-emerald-700" @click="startNew">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Novo endpoint
                                </Button>
                                <Button variant="outline" :disabled="loading" @click="loadAll">
                                    Atualizar
                                </Button>
                            </div>
                        </div>

                        <div class="flex-1 px-4 pb-6">
                            <h3 class="mb-3 text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                                Meus endpoints ({{ localEndpoints.length }})
                            </h3>
                            <ul v-if="localEndpoints.length > 0" class="space-y-3">
                                <li
                                    v-for="row in localEndpoints"
                                    :key="row.id"
                                    class="rounded-2xl border border-zinc-200/80 bg-zinc-50/80 shadow-sm transition-shadow hover:shadow dark:border-zinc-700/60 dark:bg-zinc-800/60"
                                >
                                    <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-medium text-zinc-900 dark:text-white">
                                                    {{ row.name }}
                                                </span>
                                                <span
                                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase"
                                                    :class="
                                                        row.is_active
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'
                                                    "
                                                >
                                                    {{ row.is_active ? 'Ativo' : 'Inativo' }}
                                                </span>
                                            </div>
                                            <div
                                                class="mt-0.5 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400"
                                                :title="row.url"
                                            >
                                                {{ truncateUrl(row.url) }}
                                            </div>
                                            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                <span>Token: {{ row.url_token_masked }}</span>
                                                <span>
                                                    Secret:
                                                    {{ row.signing_secret_set ? 'definido' : 'não definido' }}
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex shrink-0 flex-wrap items-center gap-1">
                                            <button
                                                type="button"
                                                class="flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-200/80 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                                @click.stop="copyText(row.url)"
                                            >
                                                <ArrowDownToLine class="h-3.5 w-3.5" />
                                                Copiar URL
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-200/80 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                                @click.stop="openEdit(row)"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/30"
                                                :disabled="regenerating === row.id"
                                                @click.stop="regenerate(row.id)"
                                            >
                                                {{ regenerating === row.id ? 'Gerando…' : 'Novo token' }}
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100 dark:text-red-400 dark:hover:bg-red-900/30"
                                                :disabled="deleting === row.id"
                                                @click.stop="remove(row.id)"
                                            >
                                                {{ deleting === row.id ? 'Removendo…' : 'Remover' }}
                                            </button>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                            <p
                                v-else-if="!loading"
                                class="rounded-2xl bg-zinc-50 py-8 text-center text-sm text-zinc-500 dark:bg-zinc-800/40 dark:text-zinc-400"
                            >
                                Nenhum endpoint configurado. Clique em "Novo endpoint" para criar.
                            </p>
                        </div>
                    </template>

                    <!-- Form -->
                    <div
                        v-else-if="currentView === 'form'"
                        class="flex flex-1 flex-col bg-zinc-50/50 p-4 dark:bg-zinc-800/30"
                    >
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Nome
                                </label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    placeholder="Ex.: Hotmart"
                                    class="block w-full rounded-xl bg-white px-4 py-2.5 text-zinc-900 shadow-sm ring-1 ring-zinc-200 placeholder-zinc-400 transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 focus:ring-offset-0 dark:bg-zinc-800 dark:ring-zinc-600 dark:text-white dark:placeholder-zinc-500"
                                />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Produto (área de membros)
                                </label>
                                <select
                                    v-model="form.product_id"
                                    class="block w-full rounded-xl bg-white px-4 py-2.5 text-sm text-zinc-900 shadow-sm ring-1 ring-zinc-200 transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 focus:ring-offset-0 dark:bg-zinc-800 dark:ring-zinc-600 dark:text-white"
                                >
                                    <option v-for="p in products" :key="p.id" :value="p.id">
                                        {{ p.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Oferta
                                    <span class="font-normal text-zinc-500">(opcional)</span>
                                </label>
                                <select
                                    v-model="form.product_offer_id"
                                    class="block w-full rounded-xl bg-white px-4 py-2.5 text-sm text-zinc-900 shadow-sm ring-1 ring-zinc-200 transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 focus:ring-offset-0 dark:bg-zinc-800 dark:ring-zinc-600 dark:text-white"
                                >
                                    <option :value="null">— Nenhuma —</option>
                                    <option v-for="o in selectedProduct?.offers || []" :key="o.id" :value="o.id">
                                        {{ o.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Plano de assinatura
                                    <span class="font-normal text-zinc-500">(opcional)</span>
                                </label>
                                <select
                                    v-model="form.subscription_plan_id"
                                    class="block w-full rounded-xl bg-white px-4 py-2.5 text-sm text-zinc-900 shadow-sm ring-1 ring-zinc-200 transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 focus:ring-offset-0 dark:bg-zinc-800 dark:ring-zinc-600 dark:text-white"
                                >
                                    <option :value="null">— Nenhum —</option>
                                    <option
                                        v-for="s in selectedProduct?.subscription_plans || []"
                                        :key="s.id"
                                        :value="s.id"
                                    >
                                        {{ s.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <Toggle v-model="form.is_active" label="Ativo" />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Secret para assinatura HMAC
                                    <span class="font-normal text-zinc-500">(opcional; em branco = sem verificação)</span>
                                </label>
                                <input
                                    v-model="form.signing_secret"
                                    type="password"
                                    autocomplete="new-password"
                                    placeholder="Deixe vazio ou defina ao criar/editar"
                                    class="block w-full rounded-xl bg-white px-4 py-2.5 text-zinc-900 shadow-sm ring-1 ring-zinc-200 placeholder-zinc-400 transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 focus:ring-offset-0 dark:bg-zinc-800 dark:ring-zinc-600 dark:text-white dark:placeholder-zinc-500"
                                />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Mapeamento JSON
                                </label>
                                <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    Para cada campo use um caminho (string) ou vários em lista (ordem de tentativa). Chaves:
                                    email, name, cpf, phone, external_id. Opcional: "_strict": true — só usa os caminhos que definir.
                                </p>
                                <div class="mb-2 flex flex-wrap gap-2">
                                    <Button type="button" size="sm" variant="outline" @click="toggleHelper">
                                        {{ showHelper ? 'Ocultar exemplo & IA' : 'Ver exemplo & IA' }}
                                    </Button>
                                    <Button type="button" size="sm" variant="outline" @click="applyExampleFieldMap">
                                        Aplicar exemplo no campo
                                    </Button>
                                </div>
                                <textarea
                                    v-model="form.field_map_json"
                                    rows="8"
                                    class="block w-full rounded-xl bg-white px-4 py-2.5 font-mono text-xs text-zinc-900 shadow-sm ring-1 ring-zinc-200 transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 focus:ring-offset-0 dark:bg-zinc-800 dark:ring-zinc-600 dark:text-white"
                                ></textarea>

                                <div
                                    v-if="showHelper"
                                    class="mt-3 space-y-3 rounded-xl border border-emerald-200 bg-emerald-50/40 p-3 dark:border-emerald-900/50 dark:bg-emerald-950/20"
                                >
                                    <div class="flex flex-wrap gap-1 border-b border-emerald-200/80 pb-2 dark:border-emerald-800/60">
                                        <button
                                            type="button"
                                            class="rounded-t px-3 py-1.5 text-xs font-medium transition-colors"
                                            :class="
                                                helperTab === 'example'
                                                    ? 'bg-white text-emerald-900 shadow-sm dark:bg-zinc-900 dark:text-emerald-200'
                                                    : 'text-zinc-600 hover:bg-white/60 dark:text-zinc-400 dark:hover:bg-zinc-900/40'
                                            "
                                            @click="helperTab = 'example'"
                                        >
                                            Exemplo pronto
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-t px-3 py-1.5 text-xs font-medium transition-colors"
                                            :class="
                                                helperTab === 'ai'
                                                    ? 'bg-white text-emerald-900 shadow-sm dark:bg-zinc-900 dark:text-emerald-200'
                                                    : 'text-zinc-600 hover:bg-white/60 dark:text-zinc-400 dark:hover:bg-zinc-900/40'
                                            "
                                            @click="helperTab = 'ai'"
                                        >
                                            Gerar prompt para IA
                                        </button>
                                    </div>

                                    <div v-show="helperTab === 'example'" class="space-y-3">
                                        <p class="text-xs text-zinc-600 dark:text-zinc-400">
                                            À esquerda: payload de exemplo típico. À direita:
                                            <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">field_map</code>
                                            adequado a essa estrutura.
                                        </p>
                                        <div class="grid gap-3 md:grid-cols-2">
                                            <div class="min-w-0">
                                                <div class="mb-1 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                                    Payload de exemplo
                                                </div>
                                                <pre
                                                    class="max-h-72 overflow-auto rounded-lg border border-zinc-200 bg-white p-2 text-[11px] leading-relaxed dark:border-zinc-600 dark:bg-zinc-900"
                                                >{{ examplePayloadJson }}</pre>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    class="mt-2"
                                                    @click="copyText(examplePayloadJson)"
                                                >
                                                    Copiar payload
                                                </Button>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="mb-1 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                                    Configuração (field_map)
                                                </div>
                                                <pre
                                                    class="max-h-72 overflow-auto rounded-lg border border-zinc-200 bg-white p-2 text-[11px] leading-relaxed dark:border-zinc-600 dark:bg-zinc-900"
                                                >{{ exampleFieldMapJson }}</pre>
                                                <div class="mt-2 flex flex-wrap gap-2">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        @click="copyText(exampleFieldMapJson)"
                                                    >
                                                        Copiar JSON
                                                    </Button>
                                                    <Button type="button" size="sm" @click="applyExampleFieldMap">
                                                        Aplicar este exemplo no campo
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div v-show="helperTab === 'ai'" class="space-y-3">
                                        <p class="text-xs text-zinc-600 dark:text-zinc-400">
                                            Cole abaixo um JSON real da sua plataforma. O prompt é gerado automaticamente quando o JSON é válido.
                                            Copie e cole no <strong>ChatGPT</strong> (ou outro assistente); depois cola o
                                            <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">field_map</code>
                                            devolvido no campo «Mapeamento JSON» acima.
                                        </p>
                                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                            Payload da sua plataforma (JSON)
                                        </label>
                                        <textarea
                                            v-model="aiPayloadInput"
                                            rows="10"
                                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-900"
                                            placeholder='Cole aqui o corpo JSON do webhook, ex.: { "data": { "customer": { "email": "..." } } }'
                                        ></textarea>
                                        <div class="flex flex-wrap gap-2">
                                            <Button type="button" size="sm" @click="copyAiPrompt">Copiar prompt</Button>
                                            <Button type="button" size="sm" variant="outline" @click="openChatGpt">
                                                Abrir ChatGPT
                                            </Button>
                                        </div>
                                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                            Prompt para colar no ChatGPT
                                        </label>
                                        <textarea
                                            readonly
                                            rows="14"
                                            class="w-full cursor-text rounded-lg border border-zinc-300 bg-zinc-50 px-3 py-2 font-mono text-[11px] leading-relaxed text-zinc-800 dark:border-zinc-600 dark:bg-zinc-900/80 dark:text-zinc-200"
                                            :value="aiGeneratedPrompt"
                                        ></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="error"
                            class="mt-4 rounded-lg bg-red-100 px-3 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300"
                        >
                            {{ error }}
                        </div>
                        <div
                            v-if="ok"
                            class="mt-4 rounded-lg bg-emerald-100 px-3 py-2 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200"
                        >
                            {{ ok }}
                        </div>

                        <div class="mt-4 flex gap-2">
                            <Button variant="outline" :disabled="saving" @click="cancelEdit">
                                Cancelar
                            </Button>
                            <Button :disabled="saving" @click="save">
                                {{ saving ? 'Salvando…' : 'Salvar' }}
                            </Button>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
