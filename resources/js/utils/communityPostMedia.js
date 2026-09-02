/** Proporções de mídia nos posts da comunidade (estilo Instagram). */
export const COMMUNITY_MEDIA_ASPECTS = {
    '1:1': {
        label: '1:1',
        description: 'Quadrado',
        class: 'aspect-square',
    },
    '4:5': {
        label: '4:5',
        description: 'Retrato',
        class: 'aspect-[4/5]',
    },
    '9:16': {
        label: '9:16',
        description: 'Vídeo vertical',
        class: 'aspect-[9/16]',
    },
};

export const COMMUNITY_IMAGE_ASPECT_OPTIONS = ['1:1', '4:5'];
export const COMMUNITY_VIDEO_ASPECT = '9:16';
export const COMMUNITY_VIDEO_RECOMMENDED = '1080×1920 px (9:16)';

export function getCommunityMediaAspectClass(aspect) {
    return COMMUNITY_MEDIA_ASPECTS[aspect]?.class ?? COMMUNITY_MEDIA_ASPECTS['4:5'].class;
}

export function resolveCommunityMediaAspect(aspect, hasVideo = false) {
    if (hasVideo) {
        return COMMUNITY_VIDEO_ASPECT;
    }
    if (aspect && COMMUNITY_MEDIA_ASPECTS[aspect]) {
        return aspect;
    }
    return '4:5';
}
