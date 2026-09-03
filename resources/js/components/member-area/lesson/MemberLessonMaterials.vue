<script setup>
import { computed, ref, watch } from 'vue';
import { Download, ExternalLink, FileText, Link2 } from 'lucide-vue-next';

const props = defineProps({
    lesson: { type: Object, default: null },
});

function normalizeSupportFiles(lesson) {
    const list = Array.isArray(lesson?.support_files) ? lesson.support_files : [];
    return list
        .map((it) => {
            if (typeof it === 'string') return { url: it, name: 'Material de apoio' };
            const url = (it?.url ?? '').toString().trim();
            if (!url) return null;
            return {
                url,
                name: (it?.name ?? 'Material de apoio').toString().trim() || 'Material de apoio',
            };
        })
        .filter(Boolean);
}

function normalizeUsefulLinks(lesson) {
    const list = Array.isArray(lesson?.useful_links) ? lesson.useful_links : [];
    return list
        .map((it) => {
            const url = (it?.url ?? '').toString().trim();
            if (!url) return null;
            const title = (it?.title ?? '').toString().trim();
            return { url, title: title || 'Link' };
        })
        .filter(Boolean);
}

const supportFiles = computed(() => normalizeSupportFiles(props.lesson));
const usefulLinks = computed(() => normalizeUsefulLinks(props.lesson));
const hasSupportFiles = computed(() => supportFiles.value.length > 0);
const hasUsefulLinks = computed(() => usefulLinks.value.length > 0);
const hasSection = computed(() => hasSupportFiles.value || hasUsefulLinks.value);
const showTabs = computed(() => hasSupportFiles.value && hasUsefulLinks.value);

const activeTab = ref('materials');

watch(
    () => [hasSupportFiles.value, hasUsefulLinks.value],
    ([support, links]) => {
        if (support && !links) activeTab.value = 'materials';
        else if (!support && links) activeTab.value = 'links';
        else if (support && links) activeTab.value = 'materials';
    },
    { immediate: true },
);
</script>

<template>
    <section v-if="hasSection" class="w-full min-w-0 max-w-full overflow-hidden rounded-2xl bg-zinc-900/50 px-4 py-4 sm:px-5">
        <div v-if="showTabs" class="mb-4 flex min-w-0 gap-1 rounded-lg bg-zinc-800/50 p-1">
            <button
                type="button"
                class="flex min-w-0 flex-1 items-center justify-center gap-1 rounded-md px-2 py-2 text-[10px] font-semibold uppercase tracking-wide transition sm:text-xs"
                :class="activeTab === 'materials'
                    ? 'bg-[var(--ma-primary)]/15 text-[var(--ma-primary)]'
                    : 'text-zinc-400 hover:text-zinc-200'"
                @click="activeTab = 'materials'"
            >
                <FileText class="h-3.5 w-3.5 shrink-0" />
                <span class="truncate">Materiais</span>
            </button>
            <button
                type="button"
                class="flex min-w-0 flex-1 items-center justify-center gap-1 rounded-md px-2 py-2 text-[10px] font-semibold uppercase tracking-wide transition sm:text-xs"
                :class="activeTab === 'links'
                    ? 'bg-[var(--ma-primary)]/15 text-[var(--ma-primary)]'
                    : 'text-zinc-400 hover:text-zinc-200'"
                @click="activeTab = 'links'"
            >
                <Link2 class="h-3.5 w-3.5 shrink-0" />
                <span class="truncate">Links</span>
            </button>
        </div>

        <div v-else class="mb-3 flex items-center gap-2">
            <FileText v-if="hasSupportFiles" class="h-4 w-4 text-[var(--ma-primary)]" />
            <Link2 v-else class="h-4 w-4 text-[var(--ma-primary)]" />
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-300">
                {{ hasSupportFiles ? 'Materiais de apoio' : 'Links úteis' }}
            </h2>
        </div>

        <div v-if="!showTabs || activeTab === 'materials'" v-show="hasSupportFiles">
            <div class="grid gap-2 sm:grid-cols-2">
                <a
                    v-for="(f, i) in supportFiles"
                    :key="`${f.url}-${i}`"
                    :href="f.url"
                    download
                    target="_blank"
                    rel="noopener"
                    class="group flex min-w-0 max-w-full items-center gap-3 overflow-hidden rounded-xl bg-zinc-800/60 px-3 py-3 text-sm text-zinc-200 transition hover:bg-zinc-800 sm:px-4"
                >
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--ma-primary)]/15 text-[var(--ma-primary)]">
                        <Download class="h-4 w-4" />
                    </span>
                    <span class="min-w-0 flex-1 truncate font-medium">{{ f.name || 'Baixar material' }}</span>
                </a>
            </div>
        </div>

        <div v-if="!showTabs || activeTab === 'links'" v-show="hasUsefulLinks">
            <div class="grid gap-2 sm:grid-cols-2">
                <a
                    v-for="(link, i) in usefulLinks"
                    :key="`${link.url}-${i}`"
                    :href="link.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="group flex min-w-0 max-w-full items-center gap-3 overflow-hidden rounded-xl bg-zinc-800/60 px-3 py-3 text-sm text-zinc-200 transition hover:bg-zinc-800 sm:px-4"
                >
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--ma-primary)]/15 text-[var(--ma-primary)]">
                        <ExternalLink class="h-4 w-4" />
                    </span>
                    <span class="min-w-0 flex-1 truncate font-medium">{{ link.title }}</span>
                </a>
            </div>
        </div>
    </section>
</template>
