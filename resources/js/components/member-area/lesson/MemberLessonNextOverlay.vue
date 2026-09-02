<script setup>
import { computed } from 'vue';
import { Play } from 'lucide-vue-next';

const props = defineProps({
    visible: { type: Boolean, default: false },
    nextTarget: { type: Object, default: null },
    countdown: { type: Number, default: 5 },
    totalSeconds: { type: Number, default: 5 },
});

const emit = defineEmits(['play-now', 'cancel']);

const progressPercent = computed(() => {
    const total = Math.max(1, props.totalSeconds);
    const elapsed = total - props.countdown;
    return Math.min(100, Math.max(0, (elapsed / total) * 100));
});

const isModule = computed(() => props.nextTarget?.kind === 'module');

const heading = computed(() => (isModule.value ? 'Próximo módulo' : 'Próxima aula'));

const subtitle = computed(() => {
    const t = props.nextTarget;
    if (!t) return '';
    if (isModule.value) {
        return t.subtitle ? `${t.title} · ${t.subtitle}` : t.title;
    }
    return t.title || '';
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 translate-y-1"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 translate-y-1"
    >
        <div
            v-if="visible && nextTarget"
            class="absolute bottom-14 right-4 z-30 flex max-w-[min(100%,18rem)] flex-col items-end sm:bottom-16 sm:right-6"
        >
            <button
                type="button"
                class="group relative w-full overflow-hidden rounded bg-white text-left shadow-lg shadow-black/40 transition hover:bg-zinc-100"
                @click="emit('play-now')"
            >
                <span
                    class="absolute inset-y-0 left-0 bg-black/20 transition-[width] duration-1000 ease-linear"
                    :style="{ width: `${progressPercent}%` }"
                    aria-hidden="true"
                />
                <span class="relative block px-3.5 py-2 sm:px-4 sm:py-2.5">
                    <span class="flex items-center gap-2 text-sm font-semibold text-black">
                        <Play class="h-3.5 w-3.5 shrink-0 fill-current" />
                        <span>{{ heading }}</span>
                        <span class="tabular-nums">{{ countdown }}</span>
                    </span>
                    <span
                        v-if="subtitle"
                        class="mt-0.5 block truncate text-[11px] font-medium text-zinc-600"
                    >
                        {{ subtitle }}
                    </span>
                </span>
            </button>
            <button
                type="button"
                class="mt-1.5 px-1 text-[11px] font-medium text-white/50 transition hover:text-white/80"
                @click.stop="emit('cancel')"
            >
                Cancelar
            </button>
        </div>
    </Transition>
</template>
