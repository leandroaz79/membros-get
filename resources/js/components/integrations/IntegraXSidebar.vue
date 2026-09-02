<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { X, Loader2, ExternalLink, MessageCircle } from 'lucide-vue-next';

const INTEGRAX_SUPPORT_WHATSAPP_URL =
    'https://wa.me/551132808396?text=' +
    encodeURIComponent(
        'Olá! Criei uma conta na IntegraX e gerei um token de API. Gostaria de solicitar a ativação do token para uso via Getfy.',
    );

const props = defineProps({
    open: { type: Boolean, default: false },
    integrax_connection: {
        type: Object,
        default: () => ({
            configured: false,
            is_active: false,
            has_token: false,
            api_token: '',
            last_tested_at: null,
            last_error: null,
        }),
    },
});

const emit = defineEmits(['close', 'saved']);

const form = ref({
    api_token: '',
    is_active: true,
});
const testPhone = ref('');
const testMessage = ref('Teste IntegraX via Getfy');
const saving = ref(false);
const testing = ref(false);
const errorMessage = ref(null);
const successMessage = ref(null);

watch(
    () => [props.open, props.integrax_connection],
    () => {
        if (props.open) {
            form.value = {
                api_token: props.integrax_connection?.api_token ?? '',
                is_active: props.integrax_connection?.is_active ?? true,
            };
            errorMessage.value = null;
            successMessage.value = null;
        }
    },
    { immediate: true },
);

async function save() {
    errorMessage.value = null;
    successMessage.value = null;
    if (!form.value.api_token?.trim() && !props.integrax_connection?.configured) {
        errorMessage.value = 'Informe o token da API IntegraX.';
        return;
    }
    saving.value = true;
    try {
        await axios.put('/integracoes/integrax', {
            api_token: form.value.api_token?.trim() || undefined,
            is_active: form.value.is_active,
        });
        successMessage.value = 'Conexão salva com sucesso.';
        emit('saved');
    } catch (e) {
        errorMessage.value = e?.response?.data?.message || 'Falha ao salvar.';
    } finally {
        saving.value = false;
    }
}

async function testConnection() {
    errorMessage.value = null;
    successMessage.value = null;
    if (!testPhone.value.trim()) {
        errorMessage.value = 'Informe um telefone para o teste (com DDD).';
        return;
    }
    testing.value = true;
    try {
        const res = await axios.post('/integracoes/integrax/test', {
            phone: testPhone.value.trim(),
            message: testMessage.value.trim() || undefined,
        });
        if (res.data?.success) {
            successMessage.value = 'SMS de teste enviado com sucesso.';
            emit('saved');
        } else {
            errorMessage.value = res.data?.message || 'Falha no teste.';
        }
    } catch (e) {
        errorMessage.value = e?.response?.data?.message || 'Falha no teste.';
    } finally {
        testing.value = false;
    }
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
                @click="emit('close')"
            />
            <aside class="relative flex h-full w-full max-w-md flex-col rounded-l-2xl bg-white shadow-2xl dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">IntegraX</h2>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">SMS — provedor de disparo</p>
                    </div>
                    <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="emit('close')">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-6 space-y-6">
                    <a
                        href="https://www.integrax.app/auth/register"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-2 text-sm font-medium text-[var(--color-primary)] hover:underline"
                    >
                        Criar conta na IntegraX
                        <ExternalLink class="h-4 w-4" />
                    </a>

                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        Cole o token da API para habilitar envios SMS. As mensagens e eventos são configurados por produto, na aba SMS.
                    </p>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Token da API</label>
                        <input
                            v-model="form.api_token"
                            type="password"
                            autocomplete="off"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                            placeholder="Token do painel IntegraX"
                        />
                    </div>

                    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-800/50 dark:bg-sky-950/30">
                        <p class="text-sm font-medium text-sky-900 dark:text-sky-100">Ativação do token</p>
                        <p class="mt-1.5 text-sm text-sky-800 dark:text-sky-200">
                            Cada token de API gerado na IntegraX precisa ser ativado pelo suporte antes de enviar SMS.
                            Após gerar o token no painel da IntegraX, entre em contato para solicitar a ativação.
                        </p>
                        <a
                            :href="INTEGRAX_SUPPORT_WHATSAPP_URL"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#25D366] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#1ebe5d]"
                        >
                            <MessageCircle class="h-4 w-4 shrink-0" aria-hidden="true" />
                            Solicitar ativação no WhatsApp
                        </a>
                        <p class="mt-2 text-center text-xs text-sky-700 dark:text-sky-300">
                            +55 11 3280-8396
                        </p>
                    </div>

                    <div class="flex items-center justify-between rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <div>
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">Integração ativa</p>
                            <p class="text-xs text-zinc-500">Desative para pausar todos os envios SMS</p>
                        </div>
                        <Toggle v-model="form.is_active" />
                    </div>

                    <div v-if="integrax_connection.last_error" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-950/30 dark:text-amber-200">
                        Último erro: {{ integrax_connection.last_error }}
                    </div>

                    <div class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
                        <h3 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Testar envio</h3>
                        <div class="space-y-3">
                            <input
                                v-model="testPhone"
                                type="text"
                                class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                placeholder="Telefone com DDD (ex: 11999999999)"
                            />
                            <input
                                v-model="testMessage"
                                type="text"
                                maxlength="160"
                                class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                placeholder="Mensagem de teste (máx. 160)"
                            />
                            <p class="text-xs text-zinc-500">{{ testMessage.length }}/160 caracteres</p>
                        </div>
                    </div>

                    <div v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800/50 dark:bg-red-950/30 dark:text-red-200">
                        {{ errorMessage }}
                    </div>
                    <div v-if="successMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-800/50 dark:bg-emerald-950/30 dark:text-emerald-200">
                        {{ successMessage }}
                    </div>
                </div>

                <div class="flex flex-wrap gap-3 border-t border-zinc-200 p-6 dark:border-zinc-700">
                    <Button type="button" :disabled="saving" @click="save">
                        <Loader2 v-if="saving" class="h-4 w-4 animate-spin" />
                        Salvar
                    </Button>
                    <button
                        type="button"
                        :disabled="testing"
                        class="rounded-xl border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-700 hover:border-[var(--color-primary)] dark:border-zinc-600 dark:text-zinc-300"
                        @click="testConnection"
                    >
                        {{ testing ? 'Enviando...' : 'Enviar teste' }}
                    </button>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
