<script setup>
import { computed } from 'vue';
import { getImageFormat } from '@/lib/checkoutContentFormats';
import { normalizeCheckoutImageUrl } from '@/lib/checkoutImageUrl';
import { retryImageOnError } from '@/lib/imageLoadRetry';

const props = defineProps({
    block: { type: Object, required: true },
    /** data-checkout placement hint */
    dataPlacement: { type: String, default: 'content' },
    imageClass: { type: String, default: '' },
});

const isImage = computed(() => props.block?.type === 'image');
const isText = computed(() => props.block?.type === 'text');

const formatMeta = computed(() => getImageFormat(props.block?.format ?? 'wide'));

/** Exibe imagem inteira (sem crop): hero, lateral e formatos retrato. */
const useContainImage = computed(() => {
    const fmt = props.block?.format ?? '';
    const place = props.dataPlacement ?? '';
    return fmt === 'hero' || fmt === 'portrait' || place === 'sidebar' || place === 'side';
});

const textAlignClass = computed(() => {
    const align = props.block?.align ?? 'center';
    if (align === 'left') return 'text-left';
    if (align === 'right') return 'text-right';
    return 'text-center';
});

const imageAlt = computed(() => {
    const alt = String(props.block?.alt ?? '').trim();
    return alt || 'Banner';
});

const linkUrl = computed(() => String(props.block?.link ?? '').trim());

const imageSrc = computed(() => normalizeCheckoutImageUrl(props.block?.url ?? ''));

const titleText = computed(() => String(props.block?.title ?? '').trim());
const bodyText = computed(() => String(props.block?.body ?? '').trim());
/** Evita título + parágrafo idênticos (parece duplicado no checkout). */
const showTitle = computed(() => titleText.value !== '' && titleText.value !== bodyText.value);
const showBody = computed(() => bodyText.value !== '');
</script>

<template>
    <div
        v-if="isText"
        class="rounded-2xl border border-white/20 bg-white/95 px-6 py-5 shadow-sm backdrop-blur"
        :class="textAlignClass"
        data-checkout="content-block-text"
        :data-block-id="block.id"
    >
        <h3 v-if="showTitle" class="text-lg font-bold tracking-tight text-gray-900 sm:text-xl">
            {{ titleText }}
        </h3>
        <p
            v-if="showBody"
            class="whitespace-pre-line text-sm leading-relaxed text-gray-600 sm:text-base"
            :class="showTitle ? 'mt-2' : ''"
        >
            {{ bodyText }}
        </p>
    </div>

    <component
        v-else-if="isImage && imageSrc"
        :is="linkUrl ? 'a' : 'div'"
        :href="linkUrl || undefined"
        :target="linkUrl ? '_blank' : undefined"
        :rel="linkUrl ? 'noopener noreferrer' : undefined"
        class="block overflow-hidden rounded-2xl shadow-xl"
        :class="linkUrl ? 'transition hover:opacity-95' : ''"
        :data-checkout="`content-block-${dataPlacement}`"
        :data-block-id="block.id"
    >
        <div
            v-if="useContainImage"
            class="w-full overflow-hidden bg-gray-100"
        >
            <img
                :src="imageSrc"
                :alt="imageAlt"
                class="block h-auto w-full object-contain"
                :class="imageClass"
                loading="lazy"
                decoding="async"
                @error="retryImageOnError"
            />
        </div>
        <div v-else class="relative w-full overflow-hidden bg-gray-100" :class="formatMeta.aspectClass">
            <img
                :src="imageSrc"
                :alt="imageAlt"
                class="absolute inset-0 h-full w-full object-cover"
                :class="imageClass"
                loading="lazy"
                decoding="async"
                @error="retryImageOnError"
            />
        </div>
    </component>
</template>
