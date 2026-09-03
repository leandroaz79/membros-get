<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Eye, EyeOff, Mail, Lock, ArrowRight, Smartphone } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import { usePwaInstall } from '@/composables/usePwaInstall';

const props = defineProps({
    slug: { type: String, required: true },
    product: { type: Object, required: true },
    variant: { type: String, default: 'v1' },
    preview: { type: Boolean, default: false },
});

const { canShowInstallButton, triggerInstall } = usePwaInstall(props.slug);
const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    if (props.preview) return;
    if (props.product.login_without_password) {
        form.post(props.product.login_without_password_url || `/m/${props.slug}/login-without-password`);
        return;
    }
    form.post(`/m/${props.slug}/login`);
}
</script>

<template>
    <form v-if="variant === 'v1'" class="space-y-5" @submit.prevent="submit">
        <div>
            <label for="ma-email" class="mb-1.5 block text-sm font-medium text-zinc-300">E-mail</label>
            <input
                id="ma-email"
                v-model="form.email"
                type="email"
                autocomplete="email"
                required
                :readonly="preview"
                class="w-full rounded-xl border border-zinc-600 bg-zinc-800/80 px-4 py-3 text-white placeholder-zinc-500 transition focus:border-[var(--ma-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--ma-primary)]/30"
                placeholder="seu@email.com"
            />
            <p v-if="form.errors.email" class="mt-1.5 text-sm text-red-400">{{ form.errors.email }}</p>
        </div>
        <div v-if="!product.login_without_password">
            <div class="mb-1.5 flex items-center justify-between">
                <label for="ma-password" class="text-sm font-medium text-zinc-300">Senha</label>
                <a
                    v-if="!preview"
                    :href="`/m/${slug}/esqueci-senha`"
                    class="text-sm font-medium transition hover:underline"
                    :style="{ color: product.primary_color || '#0ea5e9' }"
                >
                    Esqueci minha senha
                </a>
            </div>
            <div class="relative">
                <input
                    id="ma-password"
                    v-model="form.password"
                    :type="showPassword ? 'text' : 'password'"
                    autocomplete="current-password"
                    required
                    :readonly="preview"
                    class="w-full rounded-xl border border-zinc-600 bg-zinc-800/80 py-3 pl-4 pr-12 text-white placeholder-zinc-500 transition focus:border-[var(--ma-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--ma-primary)]/30"
                    placeholder="••••••••"
                />
                <button
                    v-if="!preview"
                    type="button"
                    class="absolute right-3 top-1/2 -translate-y-1/2 rounded p-1.5 text-zinc-400 transition hover:text-zinc-200"
                    :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'"
                    @click="showPassword = !showPassword"
                >
                    <Eye v-if="showPassword" class="h-5 w-5" />
                    <EyeOff v-else class="h-5 w-5" />
                </button>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <input
                id="ma-remember"
                v-model="form.remember"
                type="checkbox"
                :disabled="preview"
                class="h-4 w-4 rounded border-zinc-600 bg-zinc-800/80 text-[var(--ma-primary)] focus:ring-[var(--ma-primary)]/50"
            />
            <label for="ma-remember" class="text-sm text-zinc-400">Lembrar de mim</label>
        </div>
        <button
            type="submit"
            class="w-full rounded-xl px-4 py-3.5 font-semibold text-white shadow-lg transition hover:opacity-95 disabled:opacity-50"
            :style="{ backgroundColor: product.primary_color || '#0ea5e9' }"
            :disabled="form.processing || preview"
        >
            {{ form.processing ? 'Entrando…' : 'Entrar' }}
        </button>
        <button
            v-if="canShowInstallButton && !preview"
            type="button"
            class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-white/30 bg-white/10 px-4 py-3 text-sm font-medium text-white backdrop-blur-sm transition hover:bg-white/20"
            @click="triggerInstall"
        >
            <Smartphone class="h-4 w-4 shrink-0" />
            Instalar App
        </button>
    </form>

    <form v-else class="space-y-5" @submit.prevent="submit">
        <div>
            <label for="ma-email-v2" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                E-mail
            </label>
            <div class="relative">
                <Mail
                    class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400 dark:text-zinc-500"
                    aria-hidden="true"
                />
                <input
                    id="ma-email-v2"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    :readonly="preview"
                    class="wl-input block w-full rounded-xl border border-zinc-200 bg-zinc-50 py-3 pl-11 pr-4 text-sm text-zinc-900 placeholder-zinc-400 transition dark:border-zinc-600 dark:bg-zinc-900/60 dark:text-white dark:placeholder-zinc-500"
                    placeholder="seu@email.com"
                />
            </div>
            <p v-if="form.errors.email" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ form.errors.email }}</p>
        </div>

        <div v-if="!product.login_without_password">
            <label for="ma-password-v2" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                Senha
            </label>
            <div class="relative">
                <Lock
                    class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400 dark:text-zinc-500"
                    aria-hidden="true"
                />
                <input
                    id="ma-password-v2"
                    v-model="form.password"
                    :type="showPassword ? 'text' : 'password'"
                    autocomplete="current-password"
                    required
                    :readonly="preview"
                    class="wl-input block w-full rounded-xl border border-zinc-200 bg-zinc-50 py-3 pl-11 pr-12 text-sm text-zinc-900 placeholder-zinc-400 transition dark:border-zinc-600 dark:bg-zinc-900/60 dark:text-white dark:placeholder-zinc-500"
                    placeholder="••••••••"
                />
                <button
                    v-if="!preview"
                    type="button"
                    class="wl-focus-ring absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-200/80 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                    :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'"
                    @click="showPassword = !showPassword"
                >
                    <Eye v-if="showPassword" class="h-4 w-4" />
                    <EyeOff v-else class="h-4 w-4" />
                </button>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 pt-1">
            <label
                for="ma-remember-v2"
                class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-zinc-600 dark:text-zinc-300"
            >
                <span
                    class="flex h-5 w-5 items-center justify-center rounded-md border border-zinc-300 bg-zinc-50 transition dark:border-zinc-600 dark:bg-zinc-900/60"
                    :class="form.remember ? 'wl-remember-active border-transparent' : ''"
                >
                    <svg
                        v-show="form.remember"
                        class="h-3 w-3 text-zinc-900"
                        viewBox="0 0 12 12"
                        fill="none"
                        aria-hidden="true"
                    >
                        <path
                            d="M2 6l3 3 5-5"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </span>
                <input id="ma-remember-v2" v-model="form.remember" type="checkbox" class="sr-only" :disabled="preview" />
                Lembrar de mim
            </label>
            <a
                v-if="!preview && !product.login_without_password"
                :href="`/m/${slug}/esqueci-senha`"
                class="wl-link shrink-0 text-sm font-medium transition hover:underline"
            >
                Esqueci minha senha
            </a>
        </div>

        <Button
            type="submit"
            class="wl-submit group mt-2 !h-12 w-full !rounded-xl !text-base !font-semibold hover:!opacity-90"
            :disabled="form.processing || preview"
        >
            <span>{{ form.processing ? 'Entrando…' : 'Entrar' }}</span>
            <ArrowRight
                class="h-4 w-4 transition-transform group-hover:translate-x-0.5"
                :class="form.processing ? 'opacity-0' : ''"
                aria-hidden="true"
            />
        </Button>

        <button
            v-if="canShowInstallButton && !preview"
            type="button"
            class="flex w-full items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-900/60 dark:text-zinc-200 dark:hover:bg-zinc-800"
            @click="triggerInstall"
        >
            <Smartphone class="h-4 w-4 shrink-0" />
            Instalar App
        </button>
    </form>
</template>
