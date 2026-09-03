<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import StudentAreaLayout from '@/Layouts/StudentAreaLayout.vue';
import Button from '@/components/ui/Button.vue';
import { MoreVertical, RotateCcw, Play, AlertCircle, CheckCircle } from 'lucide-vue-next';

defineOptions({ layout: StudentAreaLayout });

defineProps({
    produtos: { type: Array, default: () => [] },
});

const openMenuId = ref(null);
const refundModalOpen = ref(false);
const refundTarget = ref(null);
const refundReason = ref('');
const refundSubmitting = ref(false);
const refundError = ref('');
const refundSuccess = ref('');

function toggleMenu(productId) {
    openMenuId.value = openMenuId.value === productId ? null : productId;
}

function closeMenus() {
    openMenuId.value = null;
}

function onDocumentClick(e) {
    if (!e.target.closest?.('[data-product-menu]')) {
        closeMenus();
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onUnmounted(() => document.removeEventListener('click', onDocumentClick));

function canShowAccess(product) {
    const action = product.access?.action;
    return action && action !== 'external' && product.access?.url;
}

function openRefundModal(product) {
    closeMenus();
    refundTarget.value = product;
    refundReason.value = '';
    refundError.value = '';
    refundSuccess.value = '';
    refundModalOpen.value = true;
}

function closeRefundModal() {
    refundModalOpen.value = false;
    refundTarget.value = null;
}

async function submitRefund() {
    const product = refundTarget.value;
    if (!product || refundSubmitting.value) return;

    const reason = refundReason.value.trim();
    if (reason.length < 10) {
        refundError.value = 'Descreva o motivo com pelo menos 10 caracteres.';
        return;
    }

    refundSubmitting.value = true;
    refundError.value = '';
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        const res = await fetch(`/meus-produtos/produtos/${product.id}/reembolso`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ reason }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            refundError.value = data.message || data.errors?.reason?.[0] || 'Não foi possível enviar a solicitação.';
            return;
        }
        refundSuccess.value = data.message || 'Solicitação enviada com sucesso.';
        if (product.refund) {
            product.refund.can_request = false;
            product.refund.existing_request = data.request ?? product.refund.existing_request;
        }
    } catch {
        refundError.value = 'Erro de conexão. Tente novamente.';
    } finally {
        refundSubmitting.value = false;
    }
}

const refundCanRequest = computed(() => refundTarget.value?.refund?.can_request ?? false);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white sm:text-2xl">Meus produtos</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Seus cursos e conteúdos adquiridos</p>
        </div>

        <div v-if="produtos.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <article
                v-for="p in produtos"
                :key="p.id"
                class="relative flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900/60"
            >
                <div v-if="p.image_url" class="aspect-[4/3] w-full bg-zinc-100 dark:bg-zinc-800">
                    <img :src="p.image_url" :alt="p.name" class="h-full w-full object-cover" />
                </div>
                <div v-else class="flex aspect-[4/3] w-full items-center justify-center bg-zinc-100 dark:bg-zinc-800">
                    <span class="text-2xl font-semibold text-zinc-400">{{ (p.name || '?')[0] }}</span>
                </div>

                <div class="flex flex-1 flex-col p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ p.name }}</h2>
                            <p class="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">{{ p.type_label }}</p>
                            <p v-if="p.purchased_at_formatted" class="mt-0.5 text-[11px] text-zinc-500">
                                Comprado em {{ p.purchased_at_formatted }}
                            </p>
                        </div>
                        <div v-if="p.refund?.enabled || p.refund?.existing_request" class="relative shrink-0" data-product-menu>
                            <button
                                type="button"
                                class="rounded-md p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                aria-label="Mais opções"
                                @click.stop="toggleMenu(p.id)"
                            >
                                <MoreVertical class="h-4 w-4" />
                            </button>
                            <div
                                v-if="openMenuId === p.id"
                                class="absolute right-0 top-full z-20 mt-1 min-w-[180px] rounded-lg border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                            >
                                <button
                                    v-if="p.refund?.can_request"
                                    type="button"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                    @click="openRefundModal(p)"
                                >
                                    <RotateCcw class="h-3.5 w-3.5" />
                                    Solicitar reembolso
                                </button>
                                <p
                                    v-else-if="p.refund?.existing_request"
                                    class="px-3 py-2 text-[11px] text-zinc-500 dark:text-zinc-400"
                                >
                                    Reembolso: {{ p.refund.existing_request.status_label || 'Em andamento' }}
                                </p>
                                <p
                                    v-else-if="p.refund?.message"
                                    class="px-3 py-2 text-[11px] text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ p.refund.message }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-if="p.has_lessons && p.progress_percent != null" class="mt-3">
                        <div class="mb-1 flex items-center justify-between text-[11px] text-zinc-500 dark:text-zinc-400">
                            <span>Progresso</span>
                            <span>{{ p.progress_percent }}%</span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                            <div
                                class="h-full rounded-full transition-all"
                                :style="{ width: `${p.progress_percent}%`, backgroundColor: 'var(--color-primary)' }"
                            />
                        </div>
                    </div>

                    <div class="mt-auto flex flex-col gap-2 pt-3">
                        <a
                            v-if="p.continue_watching?.url"
                            :href="p.continue_watching.url"
                            class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-medium text-zinc-800 transition hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700"
                            :title="p.continue_watching.lesson_title"
                        >
                            <Play class="h-3.5 w-3.5 shrink-0" />
                            <span class="truncate">Continuar: {{ p.continue_watching.lesson_title }}</span>
                        </a>

                        <a
                            v-if="canShowAccess(p)"
                            :href="p.access.url"
                            class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-xs font-medium text-white transition hover:opacity-90"
                            :style="{ backgroundColor: 'var(--color-primary)' }"
                        >
                            {{ p.access.label || 'Acessar' }}
                        </a>
                        <p
                            v-else-if="p.access?.action === 'external'"
                            class="text-xs text-zinc-600 dark:text-zinc-400"
                        >
                            {{ p.access.message }}
                        </p>
                    </div>
                </div>
            </article>
        </div>

        <p v-else class="rounded-xl border border-dashed border-zinc-300 px-6 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700">
            Você ainda não tem acesso a nenhum produto.
        </p>
    </div>

    <Teleport to="body">
        <div
            v-if="refundModalOpen && refundTarget"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="closeRefundModal"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-zinc-900">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Solicitar reembolso — {{ refundTarget.name }}
                </h2>
                <template v-if="refundCanRequest">
                    <p v-if="refundTarget.refund?.days_remaining != null" class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        Você tem {{ refundTarget.refund.days_remaining }} dia(s) restante(s).
                    </p>
                    <textarea
                        v-model="refundReason"
                        rows="4"
                        class="mt-4 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        placeholder="Descreva o motivo do reembolso (mínimo 10 caracteres)…"
                    />
                    <div v-if="refundError" class="mt-3 flex items-center gap-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
                        <AlertCircle class="h-4 w-4 shrink-0" />
                        {{ refundError }}
                    </div>
                    <div v-if="refundSuccess" class="mt-3 flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <CheckCircle class="h-4 w-4 shrink-0" />
                        {{ refundSuccess }}
                    </div>
                    <div class="mt-4 flex gap-2">
                        <Button type="button" variant="outline" class="flex-1" @click="closeRefundModal">Cancelar</Button>
                        <Button type="button" variant="primary" class="flex-1" :disabled="refundSubmitting" @click="submitRefund">
                            {{ refundSubmitting ? 'Enviando…' : 'Enviar solicitação' }}
                        </Button>
                    </div>
                </template>
                <template v-else>
                    <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ refundTarget.refund?.message || 'Não é possível solicitar reembolso no momento.' }}
                    </p>
                    <Button type="button" class="mt-4 w-full" @click="closeRefundModal">Fechar</Button>
                </template>
            </div>
        </div>
    </Teleport>
</template>
