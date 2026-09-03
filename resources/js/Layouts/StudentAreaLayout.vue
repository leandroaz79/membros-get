<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ThemeToggler from '@/components/layout/ThemeToggler.vue';
import { ChevronDown, LogOut } from 'lucide-vue-next';

const page = usePage();
const branding = computed(() => page.props.public_branding ?? {});
const primary = computed(() => branding.value.theme_primary || '#00cc00');
const appName = computed(() => branding.value.app_name || 'Getfy');
const logoLight = computed(() => branding.value.app_logo || branding.value.app_logo_icon || 'https://cdn.getfy.cloud/collapsed-logo.png');
const logoDark = computed(() => branding.value.app_logo_dark || branding.value.app_logo_icon_dark || logoLight.value);
const user = computed(() => page.props.auth?.user ?? null);

const accountMenuOpen = ref(false);
const accountMenuRef = ref(null);

const initials = computed(() => {
    if (!user.value?.name) return '?';
    const parts = String(user.value.name).trim().split(/\s+/);
    if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    return (parts[0][0] || '?').toUpperCase();
});

function onDocumentClick(e) {
    if (accountMenuRef.value && !accountMenuRef.value.contains(e.target)) {
        accountMenuOpen.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onUnmounted(() => document.removeEventListener('click', onDocumentClick));
</script>

<template>
    <div
        class="min-h-screen bg-zinc-50 text-zinc-900 transition-colors dark:bg-zinc-950 dark:text-zinc-100"
        :style="{ '--color-primary': primary }"
    >
        <header class="sticky top-0 z-20 border-b border-zinc-200/80 bg-white/90 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-900/90">
            <div class="mx-auto flex h-14 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <Link href="/meus-produtos" class="flex shrink-0 items-center">
                    <img :src="logoLight" :alt="appName" class="h-8 max-w-[160px] object-contain object-left dark:hidden" />
                    <img :src="logoDark" :alt="appName" class="hidden h-8 max-w-[160px] object-contain object-left dark:block" />
                </Link>

                <div class="flex items-center gap-1.5 sm:gap-2">
                    <ThemeToggler compact />

                    <div v-if="user" ref="accountMenuRef" class="relative">
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-full py-0.5 pl-0.5 pr-1.5 text-sm transition hover:bg-zinc-100 dark:hover:bg-zinc-800/80"
                            :aria-expanded="accountMenuOpen"
                            @click.stop="accountMenuOpen = !accountMenuOpen"
                        >
                            <span
                                v-if="!user.avatar_url"
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white ring-2 ring-white dark:ring-zinc-900"
                                :style="{ backgroundColor: primary }"
                            >
                                {{ initials }}
                            </span>
                            <img
                                v-else
                                :src="user.avatar_url"
                                :alt="user.name"
                                class="h-8 w-8 shrink-0 rounded-full object-cover ring-2 ring-white dark:ring-zinc-900"
                            />
                            <ChevronDown class="hidden h-3.5 w-3.5 text-zinc-400 transition sm:block" :class="{ 'rotate-180': accountMenuOpen }" />
                        </button>

                        <div
                            v-show="accountMenuOpen"
                            class="absolute right-0 top-full z-30 mt-2 w-56 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                        >
                            <div class="border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                                <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ user.name }}</p>
                                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ user.email }}</p>
                            </div>
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                class="flex w-full items-center gap-2 px-4 py-3 text-left text-sm text-zinc-700 transition hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                @click="accountMenuOpen = false"
                            >
                                <LogOut class="h-4 w-4" />
                                Sair
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-8">
            <slot />
        </main>
    </div>
</template>
