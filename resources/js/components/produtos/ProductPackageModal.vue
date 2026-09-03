<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import { Download, Upload, Package, X } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    mode: { type: String, default: 'import' }, // import | export
    product: { type: Object, default: null },
});

const emit = defineEmits(['update:open', 'imported']);

const busy = ref(false);
const error = ref('');
const warnings = ref([]);
const includeMedia = ref(true);
const file = ref(null);
const preview = ref(null);

const title = computed(() => (props.mode === 'export' ? 'Exportar produto' : 'Importar produto'));

watch(
    () => props.open,
    (v) => {
        if (!v) {
            busy.value = false;
            error.value = '';
            warnings.value = [];
            file.value = null;
            preview.value = null;
            includeMedia.value = true;
        }
    }
);

function close() {
    emit('update:open', false);
}

function onFileChange(e) {
    const f = e.target?.files?.[0] || null;
    file.value = f;
    preview.value = null;
    error.value = '';
    warnings.value = [];
}

async function runPreview() {
    if (!file.value) {
        error.value = 'Selecione um arquivo .getfy-product.';
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        const fd = new FormData();
        fd.append('package', file.value);
        const { data } = await axios.post('/produtos/import/preview', fd, {
            headers: { Accept: 'application/json' },
        });
        if (!data?.success) {
            error.value = data?.message || 'Pacote inválido.';
            return;
        }
        preview.value = data;
        warnings.value = Array.isArray(data.warnings) ? data.warnings : [];
    } catch (err) {
        error.value = err.response?.data?.message || 'Falha ao ler o pacote.';
    } finally {
        busy.value = false;
    }
}

async function runImport() {
    if (!file.value) {
        error.value = 'Selecione um arquivo .getfy-product.';
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        const fd = new FormData();
        fd.append('package', file.value);
        const { data } = await axios.post('/produtos/import', fd, {
            headers: { Accept: 'application/json' },
        });
        if (!data?.success) {
            error.value = data?.message || 'Falha na importação.';
            warnings.value = Array.isArray(data?.warnings) ? data.warnings : [];
            return;
        }
        warnings.value = Array.isArray(data.warnings) ? data.warnings : [];
        emit('imported', data);
        close();
        router.reload({ preserveScroll: true });
    } catch (err) {
        error.value = err.response?.data?.message || 'Falha na importação.';
        warnings.value = Array.isArray(err.response?.data?.warnings) ? err.response.data.warnings : [];
    } finally {
        busy.value = false;
    }
}

async function runExport() {
    if (!props.product?.id) {
        error.value = 'Produto inválido.';
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        const res = await axios.post(
            `/produtos/${props.product.id}/export`,
            { include_media: includeMedia.value },
            { responseType: 'blob', headers: { Accept: 'application/zip, application/json' } }
        );

        const contentType = res.headers['content-type'] || '';
        if (contentType.includes('application/json')) {
            const text = await res.data.text();
            const json = JSON.parse(text);
            error.value = json.message || 'Falha ao exportar.';
            return;
        }

        const disposition = res.headers['content-disposition'] || '';
        let filename = `${props.product.name || 'produto'}.getfy-product`;
        const match = disposition.match(/filename="?([^"]+)"?/i);
        if (match?.[1]) {
            filename = match[1];
        }

        const url = window.URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
        close();
    } catch (err) {
        if (err.response?.data instanceof Blob) {
            try {
                const text = await err.response.data.text();
                const json = JSON.parse(text);
                error.value = json.message || 'Falha ao exportar.';
            } catch {
                error.value = 'Falha ao exportar.';
            }
        } else {
            error.value = err.response?.data?.message || 'Falha ao exportar.';
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[100001] flex items-center justify-center p-4"
            aria-modal="true"
            role="dialog"
        >
            <div class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/60" @click="close" />
            <div
                class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
            >
                <div class="mb-5 flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ title }}</h3>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            <template v-if="mode === 'export'">
                                Gera um pacote <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">.getfy-product</code>
                                com checkout, ofertas/planos e área de membros.
                            </template>
                            <template v-else>
                                Cria um produto novo a partir de um pacote exportado.
                            </template>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div v-if="mode === 'export'" class="space-y-4">
                    <p class="text-sm text-zinc-700 dark:text-zinc-300">
                        Produto: <strong>{{ product?.name }}</strong>
                    </p>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <input v-model="includeMedia" type="checkbox" class="mt-1" />
                        <span class="text-sm text-zinc-700 dark:text-zinc-300">
                            <span class="font-medium text-zinc-900 dark:text-white">Incluir imagens e arquivos</span>
                            <span class="mt-0.5 block text-xs text-zinc-500">
                                Capa, banners do checkout, thumbnails, PDFs e mídias da área de membros. Vídeos externos (YouTube etc.) permanecem como link.
                            </span>
                        </span>
                    </label>
                </div>

                <div v-else class="space-y-4">
                    <div>
                        <label class="text-xs font-medium uppercase tracking-wide text-zinc-500">Arquivo</label>
                        <input
                            type="file"
                            accept=".zip,.getfy-product,application/zip"
                            class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            @change="onFileChange"
                        />
                        <p v-if="file" class="mt-1 text-xs text-zinc-500">{{ file.name }}</p>
                    </div>

                    <div v-if="preview?.summary" class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800/50">
                        <p class="font-medium text-zinc-900 dark:text-white">
                            {{ preview.summary.name || 'Produto' }}
                        </p>
                        <p class="mt-1 text-xs text-zinc-500">
                            {{ preview.summary.type }} ·
                            {{ preview.summary.sections || 0 }} seções ·
                            {{ preview.summary.modules || 0 }} módulos ·
                            {{ preview.summary.lessons || 0 }} aulas
                            <template v-if="preview.include_media">
                                · {{ preview.media_files || 0 }} arquivos de mídia
                            </template>
                        </p>
                    </div>
                </div>

                <p
                    v-if="error"
                    class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300"
                >
                    {{ error }}
                </p>

                <ul
                    v-if="warnings.length"
                    class="mt-3 max-h-28 space-y-1 overflow-y-auto rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200"
                >
                    <li v-for="(w, i) in warnings" :key="i">{{ w }}</li>
                </ul>

                <div class="mt-6 flex flex-wrap justify-end gap-2">
                    <Button variant="outline" :disabled="busy" @click="close">Cancelar</Button>
                    <template v-if="mode === 'export'">
                        <Button variant="primary" :disabled="busy" @click="runExport">
                            <Download class="h-4 w-4" />
                            {{ busy ? 'Gerando…' : 'Baixar pacote' }}
                        </Button>
                    </template>
                    <template v-else>
                        <Button variant="outline" :disabled="busy || !file" @click="runPreview">
                            <Package class="h-4 w-4" />
                            Pré-visualizar
                        </Button>
                        <Button variant="primary" :disabled="busy || !file" @click="runImport">
                            <Upload class="h-4 w-4" />
                            {{ busy ? 'Importando…' : 'Importar' }}
                        </Button>
                    </template>
                </div>
            </div>
        </div>
    </Teleport>
</template>
