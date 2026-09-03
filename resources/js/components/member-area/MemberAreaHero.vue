<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps({
    hero: { type: Object, default: () => ({}) },
    productName: { type: String, default: '' },
    /** Extra content under the subtitle (e.g. progress in builder preview) */
    showProgress: { type: Boolean, default: false },
    progressPercent: { type: Number, default: 0 },
    compact: { type: Boolean, default: false },
    /** Compensa header fixo da área real (-mt-14). Desligar no preview do builder. */
    offsetHeader: { type: Boolean, default: true },
});

const heroGradient = 'linear-gradient(135deg, var(--ma-primary) 0%, #27272a 100%)';

const slides = computed(() => {
    const h = props.hero ?? {};
    const raw = Array.isArray(h.slides) ? h.slides : [];
    const fromSlides = raw
        .map((s, index) => ({
            id: s?.id ?? `slide-${index}`,
            image_url_desktop: s?.image_url_desktop || s?.image_url || '',
            image_url_mobile: s?.image_url_mobile || s?.image_url_desktop || s?.image_url || '',
            title: (s?.title ?? '').toString(),
            subtitle: (s?.subtitle ?? '').toString(),
        }))
        .filter((s) => s.image_url_desktop || s.image_url_mobile || s.title || s.subtitle);

    if (fromSlides.length) return fromSlides;

    const desk = h.image_url_desktop || h.image_url || '';
    const mob = h.image_url_mobile || desk;
    if (desk || mob || h.title || h.subtitle) {
        return [{
            id: 'legacy',
            image_url_desktop: desk,
            image_url_mobile: mob,
            title: (h.title ?? '').toString(),
            subtitle: (h.subtitle ?? '').toString(),
        }];
    }
    return [{
        id: 'empty',
        image_url_desktop: '',
        image_url_mobile: '',
        title: '',
        subtitle: '',
    }];
});

const overlayOpacity = computed(() => {
    const raw = props.hero?.overlay_opacity ?? 50;
    return (raw <= 1 ? raw * 100 : raw) / 100;
});

const autoplayEnabled = computed(() => props.hero?.autoplay !== false);
const autoplayIntervalMs = computed(() => {
    const sec = Number(props.hero?.autoplay_interval ?? 5);
    return Math.max(2, Math.min(30, Number.isFinite(sec) ? sec : 5)) * 1000;
});

const currentIndex = ref(0);
const paused = ref(false);
let timer = null;

const currentSlide = computed(() => slides.value[currentIndex.value] ?? slides.value[0]);
const hasMultiple = computed(() => slides.value.length > 1);

const displayTitle = computed(() =>
    currentSlide.value?.title || props.hero?.title || props.productName || ''
);
const displaySubtitle = computed(() => {
    const slide = currentSlide.value;
    if (!slide) return props.hero?.subtitle || '';
    if (slide.subtitle) return slide.subtitle;
    if (slide.title) return '';
    return props.hero?.subtitle || '';
});

function goTo(index) {
    const total = slides.value.length;
    if (total <= 0) return;
    currentIndex.value = ((index % total) + total) % total;
    restartTimer();
}

function next() {
    goTo(currentIndex.value + 1);
}

function prev() {
    goTo(currentIndex.value - 1);
}

function clearTimer() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

function restartTimer() {
    clearTimer();
    if (!autoplayEnabled.value || !hasMultiple.value || paused.value) return;
    timer = setInterval(() => {
        currentIndex.value = (currentIndex.value + 1) % slides.value.length;
    }, autoplayIntervalMs.value);
}

function onEnter() {
    paused.value = true;
    clearTimer();
}

function onLeave() {
    paused.value = false;
    restartTimer();
}

watch([slides, autoplayEnabled, autoplayIntervalMs], () => {
    if (currentIndex.value >= slides.value.length) {
        currentIndex.value = 0;
    }
    restartTimer();
});

onMounted(() => restartTimer());
onUnmounted(() => clearTimer());
</script>

<template>
    <section
        class="relative -mx-6 flex items-end justify-start overflow-hidden bg-cover bg-center px-8 pb-10 pt-24 md:px-10 md:pb-14 md:pt-28"
        :class="[
            compact ? 'min-h-[40vh] md:min-h-[48vh]' : 'min-h-[55vh] md:min-h-[65vh]',
            offsetHeader ? '-mt-14' : '',
        ]"
        :style="{ backgroundImage: heroGradient }"
        @mouseenter="onEnter"
        @mouseleave="onLeave"
    >
        <div
            v-for="(slide, index) in slides"
            :key="slide.id"
            class="absolute inset-0 transition-opacity duration-700 ease-out"
            :class="index === currentIndex ? 'opacity-100' : 'pointer-events-none opacity-0'"
            :aria-hidden="index !== currentIndex"
        >
            <div
                v-if="slide.image_url_desktop"
                class="absolute inset-0 hidden bg-cover bg-center md:block"
                :style="{ backgroundImage: `url(${slide.image_url_desktop})` }"
            />
            <div
                v-if="slide.image_url_mobile || slide.image_url_desktop"
                class="absolute inset-0 bg-cover bg-center md:hidden"
                :style="{ backgroundImage: `url(${slide.image_url_mobile || slide.image_url_desktop})` }"
            />
        </div>

        <div
            class="absolute inset-0 bg-black"
            :style="{ opacity: overlayOpacity }"
        />
        <div
            class="pointer-events-none absolute inset-x-0 bottom-0 h-1/2"
            :style="{ background: `linear-gradient(to top, var(--ma-bg) 0%, transparent 100%)` }"
        />

        <div class="relative z-10 max-w-2xl">
            <transition
                mode="out-in"
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="opacity-0 translate-y-1"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div :key="currentSlide?.id || currentIndex">
                    <h1 class="text-4xl font-bold text-white drop-shadow-lg md:text-5xl">
                        {{ displayTitle }}
                    </h1>
                    <p v-if="displaySubtitle" class="mt-3 text-xl text-white/90 drop-shadow md:text-2xl">
                        {{ displaySubtitle }}
                    </p>
                    <p v-if="showProgress" class="mt-5 text-sm text-white/80 md:text-base">
                        Seu progresso: {{ progressPercent }}%
                    </p>
                </div>
            </transition>
        </div>

        <template v-if="hasMultiple">
            <button
                type="button"
                class="absolute left-3 top-1/2 z-20 hidden -translate-y-1/2 rounded-full bg-black/40 p-2 text-white backdrop-blur-sm transition hover:bg-black/60 md:left-4 md:inline-flex"
                aria-label="Slide anterior"
                @click="prev"
            >
                <ChevronLeft class="h-5 w-5" />
            </button>
            <button
                type="button"
                class="absolute right-3 top-1/2 z-20 hidden -translate-y-1/2 rounded-full bg-black/40 p-2 text-white backdrop-blur-sm transition hover:bg-black/60 md:right-4 md:inline-flex"
                aria-label="Próximo slide"
                @click="next"
            >
                <ChevronRight class="h-5 w-5" />
            </button>

            <div class="absolute bottom-4 left-1/2 z-20 flex -translate-x-1/2 items-center gap-2 md:bottom-6">
                <button
                    v-for="(slide, index) in slides"
                    :key="`dot-${slide.id}`"
                    type="button"
                    class="h-2 rounded-full transition-all"
                    :class="index === currentIndex ? 'w-6 bg-white' : 'w-2 bg-white/40 hover:bg-white/70'"
                    :aria-label="`Ir para slide ${index + 1}`"
                    :aria-current="index === currentIndex ? 'true' : undefined"
                    @click="goTo(index)"
                />
            </div>
        </template>
    </section>
</template>
