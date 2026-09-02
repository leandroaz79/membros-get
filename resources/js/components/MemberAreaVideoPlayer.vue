<script setup>
import { computed, ref, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { getVideoProviderType } from '@/lib/utils';
import { ensureVidstackLoaded } from '@/lib/loadVidstack';
import { Maximize2, Minimize2, Play, Pause, Monitor, Gauge, Volume2, Volume1, VolumeX } from 'lucide-vue-next';

const props = defineProps({
    src: { type: String, default: '' },
    poster: { type: String, default: '' },
    playsinline: { type: Boolean, default: true },
    autoplay: { type: Boolean, default: false },
    theater: { type: Boolean, default: false },
    watermarkEnabled: { type: Boolean, default: false },
    watermarkData: { type: Object, default: null },
});

const emit = defineEmits(['ended', 'progress']);

const watermarkPosition = ref(0);
let watermarkInterval = null;

const POSITIONS = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'];

const providerType = computed(() => getVideoProviderType(props.src));
/** YouTube/Vimeo no iOS: Fullscreen API no player inteiro falha; Vidstack usa fullscreen no iframe do provider. */
const isEmbedProvider = computed(() => {
    const t = providerType.value;
    return t === 'youtube' || t === 'vimeo';
});
const isYoutube = computed(() => providerType.value === 'youtube' && !!props.src);
/** Quando a IFrame API falha (CSP, bloqueador, timeout), usa Vidstack como na referência open source. */
const useVidstackFallback = ref(false);
const useLegacyYoutube = computed(() => isYoutube.value && !useVidstackFallback.value);
const showVidstackPlayer = computed(() => !!props.src?.trim() && (!isYoutube.value || useVidstackFallback.value));
const vidstackReady = ref(false);

watch(
    showVidstackPlayer,
    async (show) => {
        if (!show) {
            return;
        }
        if (vidstackReady.value) {
            return;
        }
        try {
            await ensureVidstackLoaded();
            vidstackReady.value = true;
        } catch (_) {
            vidstackReady.value = false;
        }
    },
    { immediate: true }
);
const isMobile = ref(false);
let mobileMql = null;
function onMobileQueryChange(e) {
    isMobile.value = !!e.matches;
}
const playerRef = ref(null);
const wrapperRef = ref(null);
const immersiveActive = ref(false);
let bodyOverflowPrev = '';
let onKeydownImmersive = null;
let onFullscreenChangeHandler = null;
let endedListener = null;
let progressListener = null;
let endedEmittedForSrc = false;
let maxWatchedPercent = 0;

const youtubeVideoId = computed(() => {
    if (!props.src) return null;
    const u = props.src.trim();
    const m = u.match(/(?:youtube\.com\/watch\?.*v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]+)/);
    return m?.[1] ?? null;
});

const hasYoutubePlaylist = computed(() => {
    if (!props.src) return false;
    try {
        const u = new URL(props.src, typeof window !== 'undefined' ? window.location.origin : 'https://example.com');
        return u.searchParams.has('list') || u.searchParams.has('playlist');
    } catch (_) {
        return /[?&]list=/.test(props.src);
    }
});

// ---------------------------------------------------------------------------
// YouTube legacy player (IFrame API) — qualidade e velocidade via API.
// Velocidade em embed YouTube/Vimeo via Vidstack pode ficar indisponível (limitação do provider).
// ---------------------------------------------------------------------------
const youtubeMountEl = ref(null);
let ytPlayer = null;
let ytApiPromise = null;
let ytApplyQualityTimer = null;
let ytProgressTimer = null;
let ytControlsHideTimer = null;

const QUALITY_STORAGE_KEY = 'member-area-youtube-quality';
const SPEED_STORAGE_KEY = 'member-area-youtube-speed';
const VOLUME_STORAGE_KEY = 'member-area-youtube-volume';
const DEFAULT_PLAYBACK_SPEED = 1;
const DEFAULT_VOLUME = 100;
const SPEED_OPTIONS_FALLBACK = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];

const qualityMenuOpen = ref(false);
const speedMenuOpen = ref(false);
const selectedQuality = ref('auto');
const selectedSpeed = ref(DEFAULT_PLAYBACK_SPEED);
const availableSpeeds = ref([...SPEED_OPTIONS_FALLBACK]);
const lastQualityError = ref(null);
const ytIsPlaying = ref(false);
const ytCurrentTime = ref(0);
const ytDuration = ref(0);
const ytBufferedPct = ref(0);
const ytProgressHover = ref(false);
const progressTrackRef = ref(null);
const volumeTrackRef = ref(null);
const ytControlsVisible = ref(true);
const ytVolume = ref(DEFAULT_VOLUME);
const ytMuted = ref(false);
const ytVolumeHover = ref(false);
const ytVolumeScrubbing = ref(false);
const ytRootEl = ref(null);
const ytPosterVisible = ref(true);
const ytScrubbing = ref(false);
const ytMaskActive = ref(false);
let ytMaskTimer = null;
const ytReady = ref(false);
const ytLoading = ref(false);
const ytLoadError = ref(false);
const ytNearEnd = ref(false);
let ytFallbackTimer = null;
let ytInitInFlight = false;
const YT_LEGACY_INIT_TIMEOUT_MS = 8000;

const ytMaskBranding = computed(() => {
    if (ytPosterVisible.value) return true;
    if (ytScrubbing.value) return true;
    return ytMaskActive.value;
});

/** Pausado após iniciar: cobre iframe para não exibir UI nativa do YouTube (título, cards, ícone). */
const ytPausedCover = computed(() => {
    if (!ytReady.value || ytIsPlaying.value || ytPosterVisible.value) return false;
    return ytCurrentTime.value > 0.5;
});

function loadYoutubeApiOnce() {
    if (typeof window === 'undefined') return Promise.reject(new Error('no_window'));
    if (ytApiPromise) return ytApiPromise;
    ytApiPromise = new Promise((resolve, reject) => {
        if (window.YT?.Player) {
            resolve(window.YT);
            return;
        }
        const existing = document.querySelector('script[data-yt-iframe-api]');
        if (!existing) {
            const s = document.createElement('script');
            s.src = 'https://www.youtube.com/iframe_api';
            s.async = true;
            s.defer = true;
            s.dataset.ytIframeApi = '1';
            s.onerror = () => reject(new Error('yt_iframe_api_load_failed'));
            document.head.appendChild(s);
        }
        const prev = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = function () {
            try {
                if (typeof prev === 'function') prev();
            } catch (_) {}
            if (window.YT?.Player) resolve(window.YT);
            else reject(new Error('yt_iframe_api_ready_but_missing'));
        };
    });
    return ytApiPromise;
}

function getSavedQuality() {
    try {
        const q = localStorage.getItem(QUALITY_STORAGE_KEY);
        return q && typeof q === 'string' ? q : 'auto';
    } catch (_) {
        return 'auto';
    }
}
function saveQuality(q) {
    try {
        localStorage.setItem(QUALITY_STORAGE_KEY, q);
    } catch (_) {}
}

function getSavedSpeed() {
    try {
        const raw = localStorage.getItem(SPEED_STORAGE_KEY);
        const n = parseFloat(raw);
        return Number.isFinite(n) && n > 0 ? n : DEFAULT_PLAYBACK_SPEED;
    } catch (_) {
        return DEFAULT_PLAYBACK_SPEED;
    }
}

function saveSpeed(rate) {
    try {
        localStorage.setItem(SPEED_STORAGE_KEY, String(rate));
    } catch (_) {}
}

function getSavedVolume() {
    try {
        const raw = localStorage.getItem(VOLUME_STORAGE_KEY);
        const n = parseInt(raw, 10);
        return Number.isFinite(n) ? Math.max(0, Math.min(100, n)) : DEFAULT_VOLUME;
    } catch (_) {
        return DEFAULT_VOLUME;
    }
}

function saveVolume(v) {
    try {
        localStorage.setItem(VOLUME_STORAGE_KEY, String(Math.max(0, Math.min(100, Math.round(v)))));
    } catch (_) {}
}

function formatSpeedLabel(rate) {
    const r = Number(rate);
    if (!Number.isFinite(r)) return '1x';
    if (r === 1) return '1x';
    const text = Number.isInteger(r) ? String(r) : String(r).replace('.', ',');
    return `${text}x`;
}

function refreshAvailableSpeeds() {
    if (!ytPlayer || typeof ytPlayer.getAvailablePlaybackRates !== 'function') {
        availableSpeeds.value = [...SPEED_OPTIONS_FALLBACK];
        return;
    }
    try {
        const rates = ytPlayer.getAvailablePlaybackRates();
        if (Array.isArray(rates) && rates.length > 0) {
            availableSpeeds.value = rates
                .filter((r) => Number.isFinite(Number(r)) && Number(r) > 0)
                .map((r) => Number(r))
                .sort((a, b) => a - b);
            return;
        }
    } catch (_) {}
    availableSpeeds.value = [...SPEED_OPTIONS_FALLBACK];
}

function resolveSpeedForVideo(requested) {
    const rates = availableSpeeds.value;
    if (!rates.length) return DEFAULT_PLAYBACK_SPEED;
    const n = Number(requested);
    if (rates.includes(n)) return n;
    return rates.reduce((best, curr) => (Math.abs(curr - n) < Math.abs(best - n) ? curr : best), rates[0]);
}

function applyYoutubeSpeed(rate) {
    if (!ytPlayer) return;
    const r = resolveSpeedForVideo(rate);
    try {
        if (typeof ytPlayer.setPlaybackRate === 'function') {
            ytPlayer.setPlaybackRate(r);
            const applied = ytPlayer.getPlaybackRate?.();
            selectedSpeed.value = Number.isFinite(applied) && applied > 0 ? applied : r;
        }
    } catch (_) {}
}

function applyYoutubeVolume() {
    if (!ytPlayer) return;
    try {
        if (ytMuted.value || ytVolume.value === 0) {
            ytPlayer.mute?.();
            return;
        }
        ytPlayer.unMute?.();
        ytPlayer.setVolume?.(ytVolume.value);
    } catch (_) {}
}

function toggleMute() {
    ytMuted.value = !ytMuted.value;
    if (!ytMuted.value && ytVolume.value === 0) {
        const saved = getSavedVolume();
        ytVolume.value = saved > 0 ? saved : 50;
    }
    applyYoutubeVolume();
    if (!ytMuted.value) saveVolume(ytVolume.value);
    showControls();
}

function setYoutubeVolume(value) {
    const vol = Math.max(0, Math.min(100, Math.round(value)));
    ytVolume.value = vol;
    ytMuted.value = vol === 0;
    applyYoutubeVolume();
    saveVolume(vol);
}

function setSpeed(rate) {
    const r = resolveSpeedForVideo(rate);
    selectedSpeed.value = r;
    saveSpeed(r);
    speedMenuOpen.value = false;
    applyYoutubeSpeed(r);
}

function closeYtMenus() {
    qualityMenuOpen.value = false;
    speedMenuOpen.value = false;
}

function toggleQualityMenu() {
    const next = !qualityMenuOpen.value;
    closeYtMenus();
    qualityMenuOpen.value = next;
}

function toggleSpeedMenu() {
    const next = !speedMenuOpen.value;
    closeYtMenus();
    speedMenuOpen.value = next;
}

function clearYtFallbackTimer() {
    if (ytFallbackTimer) {
        clearTimeout(ytFallbackTimer);
        ytFallbackTimer = null;
    }
}

function enableVidstackFallback() {
    if (useVidstackFallback.value) return;
    clearYtFallbackTimer();
    destroyYoutubePlayer();
    useVidstackFallback.value = true;
    ytLoading.value = false;
    ytLoadError.value = true;
}

function destroyYoutubePlayer() {
    clearYtFallbackTimer();
    ytInitInFlight = false;
    ytReady.value = false;
    ytLoading.value = false;
    if (ytApplyQualityTimer) {
        clearTimeout(ytApplyQualityTimer);
        ytApplyQualityTimer = null;
    }
    if (ytProgressTimer) {
        clearInterval(ytProgressTimer);
        ytProgressTimer = null;
    }
    if (ytControlsHideTimer) {
        clearTimeout(ytControlsHideTimer);
        ytControlsHideTimer = null;
    }
    try {
        if (ytPlayer && typeof ytPlayer.destroy === 'function') ytPlayer.destroy();
    } catch (_) {}
    ytPlayer = null;
    closeYtMenus();
    availableSpeeds.value = [...SPEED_OPTIONS_FALLBACK];
    lastQualityError.value = null;
    ytIsPlaying.value = false;
    ytCurrentTime.value = 0;
    ytDuration.value = 0;
    ytBufferedPct.value = 0;
    ytProgressHover.value = false;
    ytVolume.value = DEFAULT_VOLUME;
    ytMuted.value = false;
    ytVolumeHover.value = false;
    ytVolumeScrubbing.value = false;
    ytControlsVisible.value = true;
    ytPosterVisible.value = true;
    ytScrubbing.value = false;
    ytMaskActive.value = false;
    ytNearEnd.value = false;
    if (ytMaskTimer) {
        clearTimeout(ytMaskTimer);
        ytMaskTimer = null;
    }
}

function maskBrandingFor(ms = 450) {
    ytMaskActive.value = true;
    if (ytMaskTimer) clearTimeout(ytMaskTimer);
    ytMaskTimer = setTimeout(() => {
        ytMaskActive.value = false;
        ytMaskTimer = null;
    }, Math.max(0, ms));
}

function applyYoutubeQuality(q) {
    lastQualityError.value = null;
    if (!ytPlayer) return;
    if (q === 'auto') {
        // YouTube não expõe auto-select via API moderna; tentar resetar para default.
        try {
            if (typeof ytPlayer.setPlaybackQuality === 'function') ytPlayer.setPlaybackQuality('default');
        } catch (_) {}
        return;
    }

    try {
        if (typeof ytPlayer.setPlaybackQuality === 'function') {
            ytPlayer.setPlaybackQuality(q);
            return;
        }
        if (typeof ytPlayer.setPlaybackQualityRange === 'function') {
            ytPlayer.setPlaybackQualityRange(q);
            return;
        }
        lastQualityError.value = 'quality_api_unavailable';
    } catch (e) {
        lastQualityError.value = 'quality_set_failed';
    }
}

async function initYoutubePlayer() {
    if (useVidstackFallback.value) return;
    destroyYoutubePlayer();
    if (!isYoutube.value || !youtubeVideoId.value) return;
    await nextTick();
    const mount = youtubeMountEl.value;
    if (!mount) return;

    ytLoading.value = true;
    ytLoadError.value = false;
    ytInitInFlight = true;
    clearYtFallbackTimer();
    ytFallbackTimer = setTimeout(() => enableVidstackFallback(), YT_LEGACY_INIT_TIMEOUT_MS);

    selectedQuality.value = getSavedQuality();
    selectedSpeed.value = getSavedSpeed();
    ytVolume.value = getSavedVolume();
    ytMuted.value = false;
    try {
        await loadYoutubeApiOnce();
    } catch (_) {
        enableVidstackFallback();
        return;
    } finally {
        ytInitInFlight = false;
    }
    if (!window.YT?.Player) {
        enableVidstackFallback();
        return;
    }

    const mountId = `yt-legacy-${Math.random().toString(36).slice(2, 10)}`;
    mount.innerHTML = `<div id="${mountId}" class="yt-legacy-iframe"></div>`;

    const pageOrigin = typeof window !== 'undefined' ? window.location.origin : '';

    ytPlayer = new window.YT.Player(mountId, {
        videoId: youtubeVideoId.value,
        host: 'https://www.youtube-nocookie.com',
        playerVars: {
            autoplay: props.autoplay ? 1 : 0,
            controls: 0,
            playsinline: 1,
            rel: 0,
            iv_load_policy: 3,
            disablekb: 1,
            fs: 0,
            autohide: 1,
            enablejsapi: 1,
            origin: pageOrigin,
            widget_referrer: pageOrigin,
            list: undefined,
            listType: undefined,
        },
        events: {
            onReady: () => {
                ytReady.value = true;
                ytLoading.value = false;
                clearYtFallbackTimer();
                refreshAvailableSpeeds();
                const speedToApply = resolveSpeedForVideo(getSavedSpeed());
                selectedSpeed.value = speedToApply;
                saveSpeed(speedToApply);
                applyYoutubeSpeed(speedToApply);
                applyYoutubeVolume();
                // Aplicar qualidade em diferentes momentos melhora a chance de pegar (como na antiga).
                applyYoutubeQuality(selectedQuality.value);
                ytApplyQualityTimer = setTimeout(() => applyYoutubeQuality(selectedQuality.value), 800);

                // Iniciar polling de progresso (API não emite eventos de timeupdate).
                ytProgressTimer = setInterval(() => {
                    try {
                        if (!ytPlayer) return;
                        const d = ytPlayer.getDuration?.();
                        if (typeof d === 'number' && d > 0) ytDuration.value = d;
                        const t = ytPlayer.getCurrentTime?.();
                        if (typeof t === 'number' && t >= 0) {
                            ytCurrentTime.value = t;
                            if (typeof d === 'number' && d > 0) {
                                ytNearEnd.value = t >= Math.max(0, d - 4);
                            }
                            emitProgress(t, d);
                        }
                        const loaded = ytPlayer.getVideoLoadedFraction?.();
                        if (typeof loaded === 'number') {
                            ytBufferedPct.value = Math.max(0, Math.min(100, loaded * 100));
                        }
                    } catch (_) {}
                }, 350);

                if (props.autoplay) {
                    try {
                        ytPlayer.playVideo?.();
                        ytIsPlaying.value = true;
                        ytPosterVisible.value = false;
                        scheduleHideControls();
                    } catch (_) {}
                }
            },
            onStateChange: (e) => {
                // PLAYING
                if (e?.data === window.YT.PlayerState?.PLAYING) {
                    ytIsPlaying.value = true;
                    ytPosterVisible.value = false;
                    scheduleHideControls();
                    applyYoutubeSpeed(selectedSpeed.value);
                    if (ytApplyQualityTimer) clearTimeout(ytApplyQualityTimer);
                    ytApplyQualityTimer = setTimeout(() => applyYoutubeQuality(selectedQuality.value), 500);
                }
                if (e?.data === window.YT.PlayerState?.PAUSED) {
                    ytIsPlaying.value = false;
                    ytControlsVisible.value = true;
                    maskBrandingFor(700);
                }
                // ENDED
                if (e?.data === window.YT.PlayerState?.ENDED) {
                    ytIsPlaying.value = false;
                    ytControlsVisible.value = true;
                    ytPosterVisible.value = true;
                    ytNearEnd.value = true;
                    onEnded();
                }
            },
        },
    });
}

function setQuality(q) {
    selectedQuality.value = q;
    saveQuality(q);
    qualityMenuOpen.value = false;
    // aplicar agora e tentar novamente após um curto delay
    applyYoutubeQuality(q);
    if (ytApplyQualityTimer) clearTimeout(ytApplyQualityTimer);
    ytApplyQualityTimer = setTimeout(() => applyYoutubeQuality(q), 600);
}

function togglePlay() {
    if (useVidstackFallback.value) return;
    if (!ytPlayer) {
        if (!ytInitInFlight && !ytLoading.value) {
            void initYoutubePlayer();
        }
        return;
    }
    if (!ytReady.value) return;
    try {
        const state = ytPlayer.getPlayerState?.();
        if (state === window.YT?.PlayerState?.PLAYING) {
            ytPlayer.pauseVideo?.();
            ytIsPlaying.value = false;
            // Pode piscar overlays do YouTube ao pausar.
            maskBrandingFor(450);
        } else {
            ytPlayer.playVideo?.();
            ytIsPlaying.value = true;
            // Pode piscar overlays do YouTube ao dar play.
            maskBrandingFor(450);
        }
    } catch (_) {}
}

const ytProgressPct = computed(() => {
    const d = ytDuration.value || 0;
    if (d <= 0) return 0;
    return Math.max(0, Math.min(100, (ytCurrentTime.value / d) * 100));
});

const PROGRESS_THUMB_RADIUS_PX = 7;

const ytThumbStyle = computed(() => {
    const pct = ytProgressPct.value;
    const r = PROGRESS_THUMB_RADIUS_PX;
    return {
        left: `clamp(${r}px, ${pct}%, calc(100% - ${r}px))`,
    };
});

const ytVolumeFillPct = computed(() => (ytMuted.value ? 0 : ytVolume.value));

const VOLUME_THUMB_RADIUS_PX = 5;

const ytVolumeThumbStyle = computed(() => {
    const pct = ytVolumeFillPct.value;
    const r = VOLUME_THUMB_RADIUS_PX;
    return {
        left: `clamp(${r}px, ${pct}%, calc(100% - ${r}px))`,
    };
});

function formatTime(seconds) {
    const s = Math.max(0, Math.floor(Number(seconds) || 0));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
    return `${m}:${String(sec).padStart(2, '0')}`;
}

function seekToPct(pct) {
    if (!ytPlayer) return;
    const d = ytDuration.value || 0;
    if (d <= 0) return;
    const t = (Math.max(0, Math.min(100, pct)) / 100) * d;
    try {
        ytPlayer.seekTo?.(t, true);
        ytCurrentTime.value = t;
        maskBrandingFor(450);
    } catch (_) {}
}

function progressPctFromClientX(clientX) {
    const track = progressTrackRef.value;
    if (!track) return 0;
    const rect = track.getBoundingClientRect();
    if (rect.width <= 0) return 0;
    return Math.max(0, Math.min(100, ((clientX - rect.left) / rect.width) * 100));
}

function onProgressPointerDown(e) {
    ytScrubbing.value = true;
    showControls();
    seekToPct(progressPctFromClientX(e.clientX));
    e.currentTarget?.setPointerCapture?.(e.pointerId);
}

function onProgressPointerMove(e) {
    if (!ytScrubbing.value) return;
    seekToPct(progressPctFromClientX(e.clientX));
}

function onProgressPointerEnd(e) {
    if (!ytScrubbing.value) return;
    e.currentTarget?.releasePointerCapture?.(e.pointerId);
    onScrubEnd();
}

function volumePctFromClientX(clientX) {
    const track = volumeTrackRef.value;
    if (!track) return ytVolume.value;
    const rect = track.getBoundingClientRect();
    if (rect.width <= 0) return ytVolume.value;
    return Math.max(0, Math.min(100, ((clientX - rect.left) / rect.width) * 100));
}

function onVolumePointerDown(e) {
    ytVolumeScrubbing.value = true;
    showControls();
    setYoutubeVolume(volumePctFromClientX(e.clientX));
    e.currentTarget?.setPointerCapture?.(e.pointerId);
}

function onVolumePointerMove(e) {
    if (!ytVolumeScrubbing.value) return;
    setYoutubeVolume(volumePctFromClientX(e.clientX));
}

function onVolumePointerEnd(e) {
    if (!ytVolumeScrubbing.value) return;
    e.currentTarget?.releasePointerCapture?.(e.pointerId);
    ytVolumeScrubbing.value = false;
    scheduleHideControls();
}

function onYoutubeOverlayInteract() {
    // Fecha menus com interação no overlay (não no iframe).
    if (qualityMenuOpen.value || speedMenuOpen.value) {
        closeYtMenus();
    }
}

function isSpeedSelected(rate) {
    return Math.abs(Number(selectedSpeed.value) - Number(rate)) < 0.01;
}

function onScrubEnd() {
    ytScrubbing.value = false;
    maskBrandingFor(350);
    scheduleHideControls();
}

function scheduleHideControls() {
    if (ytControlsHideTimer) clearTimeout(ytControlsHideTimer);
    if (!ytIsPlaying.value) {
        ytControlsVisible.value = true;
        return;
    }
    ytControlsHideTimer = setTimeout(() => {
        ytControlsVisible.value = false;
    }, 2200);
}

function showControls() {
    ytControlsVisible.value = true;
    scheduleHideControls();
}

function enterImmersiveMode() {
    if (immersiveActive.value) return;
    immersiveActive.value = true;
    if (typeof document !== 'undefined') {
        bodyOverflowPrev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    }
    if (isMobile.value) {
        void lockOrientationLandscape();
    }
}

function exitImmersiveMode() {
    if (!immersiveActive.value) return;
    immersiveActive.value = false;
    if (typeof document !== 'undefined') {
        document.body.style.overflow = bodyOverflowPrev;
        bodyOverflowPrev = '';
    }
    unlockOrientation();
}

async function tryEnterFullscreen(el) {
    if (!el) return false;
    try {
        if (el.requestFullscreen) {
            await el.requestFullscreen();
            return true;
        }
        if (el.webkitRequestFullscreen) {
            await el.webkitRequestFullscreen();
            return true;
        }
    } catch (_) {}
    return false;
}

/**
 * Tela cheia: Fullscreen API quando suportado; no mobile usa modo imersivo como fallback + landscape.
 */
async function requestMemberVideoFullscreen() {
    if (immersiveActive.value) {
        exitImmersiveMode();
        return;
    }

    const wrap = wrapperRef.value;
    if (typeof document !== 'undefined') {
        const fsEl = document.fullscreenElement || document.webkitFullscreenElement;
        if (fsEl && wrap && (fsEl === wrap || wrap.contains(fsEl))) {
            try {
                if (document.exitFullscreen) await document.exitFullscreen();
                else if (document.webkitExitFullscreen) await document.webkitExitFullscreen();
            } catch (_) {}
            unlockOrientation();
            return;
        }
    }

    if (isMobile.value && wrap) {
        const ok = await tryEnterFullscreen(wrap);
        if (ok) {
            await lockOrientationLandscape();
            return;
        }
        enterImmersiveMode();
        return;
    }

    if (useLegacyYoutube.value && wrap) {
        const ok = await tryEnterFullscreen(wrap);
        if (!ok) {
            enterImmersiveMode();
        }
        return;
    }

    const el = playerRef.value;
    if (el) {
        try {
            if (typeof el.enterFullscreen === 'function') {
                await el.enterFullscreen('provider');
                return;
            }
        } catch (_) {
            /* fallback abaixo */
        }
        if (wrap) {
            const ok = await tryEnterFullscreen(wrap);
            if (ok) {
                return;
            }
            enterImmersiveMode();
        }
        return;
    }

    if (wrap) {
        enterImmersiveMode();
    }
}

async function lockOrientationLandscape() {
    try {
        if (typeof screen === 'undefined') return;
        if (!screen.orientation || typeof screen.orientation.lock !== 'function') return;
        await screen.orientation.lock('landscape');
    } catch (_) {}
}
function unlockOrientation() {
    try {
        if (typeof screen === 'undefined') return;
        if (!screen.orientation || typeof screen.orientation.unlock !== 'function') return;
        screen.orientation.unlock();
    } catch (_) {}
}
function isPlayerFullscreen() {
    if (typeof document === 'undefined') return false;
    const fsEl = document.fullscreenElement || document.webkitFullscreenElement;
    if (!fsEl) return false;
    const wrap = wrapperRef.value;
    if (wrap && (fsEl === wrap || wrap.contains(fsEl))) {
        return true;
    }
    const el = playerRef.value;
    if (!el) return false;
    return fsEl === el || (typeof el.contains === 'function' && el.contains(fsEl));
}

// Vidstack 1.x aceita URL completa (YouTube, Vimeo ou nativo) no src do media-player
const vidstackSrc = computed(() => {
    if (!props.src || !props.src.trim()) return '';
    const u = props.src.trim();
    const type = providerType.value;
    if (type === 'youtube') {
        const m = u.match(/(?:youtube\.com\/watch\?.*v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]+)/);
        // YouTube embed não permite forçar qualidade via API; `vq` é best-effort para reduzir casos de começar muito baixo.
        return m ? `youtube/${m[1]}?vq=hd1080&playsinline=1&rel=0&modestbranding=1&controls=0&iv_load_policy=3&fs=0` : u;
    }
    if (type === 'vimeo') {
        const m = u.match(/vimeo\.com\/(?:video\/)?(\d+)/);
        return m ? `vimeo/${m[1]}` : u;
    }
    return u;
});

// Para YouTube: usar thumbnail como poster quando não houver poster customizado, assim o botão do YouTube não aparece no centro
const posterUrl = computed(() => {
    if (props.poster) return props.poster;
    if (providerType.value !== 'youtube' || !props.src) return '';
    const m = props.src.trim().match(/(?:youtube\.com\/watch\?.*v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]+)/);
    if (!m) return '';
    const id = m[1];
    return `https://img.youtube.com/vi/${id}/sddefault.jpg`;
});

const watermarkText = computed(() => {
    if (!props.watermarkEnabled || !props.watermarkData) return '';
    const d = props.watermarkData;
    const name = (d.name ?? '').trim() || 'Aluno';
    if (d.cpf && String(d.cpf).trim()) {
        return `${name} - ${String(d.cpf).trim()}`;
    }
    return (d.email && String(d.email).trim()) ? `${name} - ${String(d.email).trim()}` : name;
});

onMounted(() => {
    if (typeof window !== 'undefined' && 'matchMedia' in window) {
        mobileMql = window.matchMedia('(max-width: 768px)');
        isMobile.value = !!mobileMql.matches;
        try {
            mobileMql.addEventListener('change', onMobileQueryChange);
        } catch (_) {
            try {
                mobileMql.addListener(onMobileQueryChange);
            } catch (_) {}
        }
    }
    if (typeof document !== 'undefined') {
        onFullscreenChangeHandler = () => {
            if (!isMobile.value) return;
            if (isPlayerFullscreen()) {
                setTimeout(() => lockOrientationLandscape(), 0);
            } else if (!immersiveActive.value) {
                unlockOrientation();
            }
        };
        document.addEventListener('fullscreenchange', onFullscreenChangeHandler);
        document.addEventListener('webkitfullscreenchange', onFullscreenChangeHandler);
        onKeydownImmersive = (e) => {
            if (e.key === 'Escape' && immersiveActive.value) {
                e.preventDefault();
                exitImmersiveMode();
            }
        };
        document.addEventListener('keydown', onKeydownImmersive);
    }
    if (props.watermarkEnabled && watermarkText.value) {
        watermarkInterval = setInterval(() => {
            watermarkPosition.value = (watermarkPosition.value + 1) % POSITIONS.length;
        }, 20000);
    }

    initYoutubePlayer();
});
onUnmounted(() => {
    detachEndedListener();
    detachProgressListener();
    if (watermarkInterval) clearInterval(watermarkInterval);
    destroyYoutubePlayer();
    exitImmersiveMode();
    if (typeof document !== 'undefined' && onFullscreenChangeHandler) {
        document.removeEventListener('fullscreenchange', onFullscreenChangeHandler);
        document.removeEventListener('webkitfullscreenchange', onFullscreenChangeHandler);
        onFullscreenChangeHandler = null;
    }
    if (typeof document !== 'undefined' && onKeydownImmersive) {
        document.removeEventListener('keydown', onKeydownImmersive);
        onKeydownImmersive = null;
    }
    unlockOrientation();
    if (mobileMql) {
        try {
            mobileMql.removeEventListener('change', onMobileQueryChange);
        } catch (_) {
            try {
                mobileMql.removeListener(onMobileQueryChange);
            } catch (_) {}
        }
    }
});

watch(
    () => [props.src, providerType.value, youtubeVideoId.value],
    () => {
        useVidstackFallback.value = false;
        ytLoadError.value = false;
        endedEmittedForSrc = false;
        maxWatchedPercent = 0;
        if (providerType.value === 'youtube') {
            void initYoutubePlayer();
        } else {
            destroyYoutubePlayer();
        }
    }
);

const effectivePlaysinline = computed(() => {
    if (providerType.value !== 'native') return props.playsinline;
    if (props.playsinline === false) return false;
    return !isMobile.value;
});

const useNativeCrossOrigin = computed(() => providerType.value === 'native');

/** Taxas alinhadas ao menu YouTube legado; embeds podem ignorar algumas taxas. */
const vidstackPlaybackRates = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];

const vidstackLayoutTranslations = {
    Settings: 'Configurações',
    Playback: 'Reprodução',
    Speed: 'Velocidade',
    Quality: 'Qualidade',
    Normal: 'Normal',
    Loop: 'Repetir',
    Captions: 'Legendas',
    Accessibility: 'Acessibilidade',
    Audio: 'Áudio',
    Auto: 'Automático',
    'Auto Quality': 'Qualidade automática',
    'Caption Styles': 'Estilo das legendas',
    Chapters: 'Capítulos',
    'Closed-Captions Off': 'Legendas desligadas',
    'Closed-Captions On': 'Legendas ligadas',
    Download: 'Download',
    Mute: 'Mudo',
    Unmute: 'Ativar som',
    Pause: 'Pausar',
    Play: 'Reproduzir',
    Fullscreen: 'Tela cheia',
    'Enter Fullscreen': 'Entrar em tela cheia',
    'Exit Fullscreen': 'Sair da tela cheia',
    'Seek Backward': 'Voltar',
    'Seek Forward': 'Avançar',
    'Playback Rate': 'Velocidade',
};

function emitProgress(currentTime, duration) {
    if (!Number.isFinite(duration) || duration <= 0 || !Number.isFinite(currentTime) || currentTime < 0) {
        return;
    }
    const percent = Math.min(100, (currentTime / duration) * 100);
    if (percent <= maxWatchedPercent) return;
    maxWatchedPercent = percent;
    emit('progress', { currentTime, duration, percent: maxWatchedPercent });
}

function onEnded() {
    if (endedEmittedForSrc) return;
    endedEmittedForSrc = true;
    emit('ended');
}

function detachEndedListener() {
    const el = playerRef.value;
    if (el && endedListener) {
        el.removeEventListener('ended', endedListener);
        el.removeEventListener('end', endedListener);
    }
    endedListener = null;
}

function attachEndedListener() {
    detachEndedListener();
    const el = playerRef.value;
    if (!el) return;
    endedListener = () => onEnded();
    el.addEventListener('ended', endedListener);
    el.addEventListener('end', endedListener);
}

function detachProgressListener() {
    const el = playerRef.value;
    if (el && progressListener) {
        el.removeEventListener('time-update', progressListener);
    }
    progressListener = null;
}

function attachProgressListener() {
    detachProgressListener();
    const el = playerRef.value;
    if (!el) return;
    progressListener = (event) => {
        const duration = Number(el.duration);
        const currentTime = Number(event?.detail?.currentTime ?? el.currentTime);
        emitProgress(currentTime, duration);
    };
    el.addEventListener('time-update', progressListener);
}

async function tryAutoplay() {
    if (!props.autoplay) return;
    const el = playerRef.value;
    if (!el) return;
    try {
        if (typeof el.play === 'function') {
            await el.play();
        }
    } catch (_) {}
}

function attachAutoplayListener() {
    const el = playerRef.value;
    if (!el || !props.autoplay) return;
    el.addEventListener('can-play', () => tryAutoplay(), { once: true });
}

watch(
    () => [vidstackReady.value, showVidstackPlayer.value, props.src, props.autoplay],
    async ([ready, show, src]) => {
        if (!ready || !show || !src?.trim()) {
            detachEndedListener();
            detachProgressListener();
            return;
        }
        endedEmittedForSrc = false;
        maxWatchedPercent = 0;
        await nextTick();
        attachEndedListener();
        attachProgressListener();
        attachAutoplayListener();
        await tryAutoplay();
    },
    { flush: 'post' },
);

function onContextMenu(e) {
    e.preventDefault();
}
</script>

<template>
    <div
        ref="wrapperRef"
        class="member-area-video-player relative aspect-video w-full max-w-full bg-black"
        :class="[
            immersiveActive ? 'is-immersive' : '',
            !immersiveActive && theater ? 'overflow-visible rounded-xl' : !immersiveActive ? 'overflow-hidden rounded-lg' : '',
        ]"
        @contextmenu.prevent="onContextMenu"
    >
        <button
            v-if="immersiveActive"
            type="button"
            class="exit-immersive-btn"
            aria-label="Sair da tela cheia"
            @click.stop.prevent="exitImmersiveMode"
        >
            <Minimize2 class="h-5 w-5" aria-hidden="true" />
            <span class="sr-only">Sair da tela cheia</span>
        </button>
        <div
            v-if="useLegacyYoutube"
            ref="ytRootEl"
            class="yt-legacy-root"
            @mousemove="showControls"
            @touchstart.passive="showControls"
        >
            <div ref="youtubeMountEl" class="yt-legacy-mount" />
            <div v-if="ytLoading && !ytReady" class="yt-loading-overlay" aria-live="polite">
                <span class="yt-loading-text">Carregando vídeo…</span>
            </div>
            <!-- Poster/máscara: esconde thumb/logo do YouTube antes do primeiro play e durante scrub/seek -->
            <div v-if="ytMaskBranding" class="yt-mask" aria-hidden="true">
                <div
                    v-if="ytPosterVisible && posterUrl"
                    class="yt-poster"
                    :style="{ backgroundImage: `url('${posterUrl}')` }"
                />
            </div>
            <div v-else-if="ytPausedCover && posterUrl" class="yt-pause-cover" aria-hidden="true">
                <img :src="posterUrl" alt="" class="yt-pause-cover-img" />
            </div>
            <div v-else-if="ytPausedCover" class="yt-pause-cover yt-pause-cover-fallback" aria-hidden="true" />
            <!-- Camada por cima do iframe para bloquear UI/overlays do YouTube (logo, menus, playlist). -->
            <button
                type="button"
                class="yt-veil"
                aria-label="Reproduzir/pausar vídeo"
                @click.stop.prevent="togglePlay"
                @pointerdown="onYoutubeOverlayInteract"
                @touchstart.passive="onYoutubeOverlayInteract"
            />

            <!-- Barra de progresso estilo YouTube -->
            <div
                class="yt-progress-overlay"
                :class="{ 'is-scrubbing': ytScrubbing, 'is-hover': ytProgressHover }"
                @pointerdown.stop="onProgressPointerDown"
                @pointermove.stop="onProgressPointerMove"
                @pointerup.stop="onProgressPointerEnd"
                @pointercancel.stop="onProgressPointerEnd"
                @mouseenter="ytProgressHover = true"
                @mouseleave="ytProgressHover = false"
            >
                <div ref="progressTrackRef" class="yt-progress-track">
                    <div class="yt-progress-buffer" :style="{ width: `${ytBufferedPct}%` }" />
                    <div class="yt-progress-played" :style="{ width: `${ytProgressPct}%` }" />
                </div>
                <span class="yt-progress-thumb" :style="ytThumbStyle" aria-hidden="true" />
            </div>

            <div class="yt-legacy-controls" :class="{ hidden: !ytControlsVisible }" @pointerdown.stop>
                <div class="yt-controlbar">
                    <button type="button" class="yt-icon-btn" aria-label="Play/Pause" @click="togglePlay">
                        <Pause v-if="ytIsPlaying" class="h-4 w-4" aria-hidden="true" />
                        <Play v-else class="h-4 w-4" aria-hidden="true" />
                    </button>

                    <div class="yt-time">
                        {{ formatTime(ytCurrentTime) }} <span class="yt-time-sep">/</span> {{ formatTime(ytDuration) }}
                    </div>

                    <div
                        class="yt-volume-wrap"
                        :class="{ 'is-hover': ytVolumeHover, 'is-scrubbing': ytVolumeScrubbing }"
                        @mouseenter="ytVolumeHover = true"
                        @mouseleave="ytVolumeHover = false"
                    >
                        <button
                            type="button"
                            class="yt-icon-btn"
                            :aria-label="ytMuted || ytVolume === 0 ? 'Ativar som' : 'Silenciar'"
                            @click="toggleMute"
                        >
                            <VolumeX v-if="ytMuted || ytVolume === 0" class="h-4 w-4" aria-hidden="true" />
                            <Volume1 v-else-if="ytVolume < 50" class="h-4 w-4" aria-hidden="true" />
                            <Volume2 v-else class="h-4 w-4" aria-hidden="true" />
                        </button>
                        <div
                            class="yt-volume-slider"
                            role="slider"
                            :aria-valuenow="ytVolumeFillPct"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-label="Volume"
                            @pointerdown.stop="onVolumePointerDown"
                            @pointermove.stop="onVolumePointerMove"
                            @pointerup.stop="onVolumePointerEnd"
                            @pointercancel.stop="onVolumePointerEnd"
                        >
                            <div ref="volumeTrackRef" class="yt-volume-track">
                                <div class="yt-volume-fill" :style="{ width: `${ytVolumeFillPct}%` }" />
                                <span class="yt-volume-thumb" :style="ytVolumeThumbStyle" aria-hidden="true" />
                            </div>
                        </div>
                    </div>

                    <button type="button" class="yt-icon-btn" aria-label="Tela cheia" @click="requestMemberVideoFullscreen">
                        <Maximize2 v-if="!immersiveActive" class="h-4 w-4" aria-hidden="true" />
                        <Minimize2 v-else class="h-4 w-4" aria-hidden="true" />
                    </button>

                    <div class="yt-menu-wrap yt-quality-menu-wrap">
                        <button
                            type="button"
                            class="yt-icon-btn"
                            aria-label="Qualidade do vídeo"
                            :aria-expanded="qualityMenuOpen"
                            @click="toggleQualityMenu"
                        >
                            <Monitor class="h-4 w-4" aria-hidden="true" />
                        </button>
                        <div
                            v-if="qualityMenuOpen"
                            class="yt-settings-menu"
                            role="menu"
                            aria-label="Qualidade do vídeo"
                            @pointerdown.stop
                        >
                            <button type="button" class="yt-settings-item" :class="{ active: selectedQuality === 'auto' }" role="menuitem" @click="setQuality('auto')">Auto</button>
                            <button type="button" class="yt-settings-item" :class="{ active: selectedQuality === 'medium' }" role="menuitem" @click="setQuality('medium')">360p</button>
                            <button type="button" class="yt-settings-item" :class="{ active: selectedQuality === 'large' }" role="menuitem" @click="setQuality('large')">480p</button>
                            <button type="button" class="yt-settings-item" :class="{ active: selectedQuality === 'hd720' }" role="menuitem" @click="setQuality('hd720')">720p</button>
                            <button type="button" class="yt-settings-item" :class="{ active: selectedQuality === 'hd1080' }" role="menuitem" @click="setQuality('hd1080')">1080p</button>
                        </div>
                    </div>

                    <div class="yt-menu-wrap">
                        <button
                            type="button"
                            class="yt-icon-btn yt-speed-btn"
                            aria-label="Velocidade de reprodução"
                            :aria-expanded="speedMenuOpen"
                            @click="toggleSpeedMenu"
                        >
                            <Gauge class="h-4 w-4" aria-hidden="true" />
                            <span class="yt-speed-btn-label">{{ formatSpeedLabel(selectedSpeed) }}</span>
                        </button>
                        <div
                            v-if="speedMenuOpen"
                            class="yt-settings-menu"
                            role="menu"
                            aria-label="Velocidade de reprodução"
                            @pointerdown.stop
                        >
                            <button
                                v-for="rate in availableSpeeds"
                                :key="rate"
                                type="button"
                                class="yt-settings-item"
                                :class="{ active: isSpeedSelected(rate) }"
                                role="menuitem"
                                @click="setSpeed(rate)"
                            >
                                {{ formatSpeedLabel(rate) }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="lastQualityError" class="yt-quality-error">
                    Não foi possível aplicar esta qualidade neste dispositivo.
                </div>
                <div v-if="hasYoutubePlaylist" class="yt-playlist-warning">
                    Este link do YouTube contém playlist; o player ignora a playlist.
                </div>
            </div>
        </div>

        <media-player
            v-else-if="showVidstackPlayer && vidstackReady"
            ref="playerRef"
            class="player"
            :src="vidstackSrc"
            :poster="posterUrl"
            :autoplay="autoplay"
            :playsinline="effectivePlaysinline"
            :fullscreen-target="isEmbedProvider ? 'provider' : undefined"
            load="eager"
            preload="auto"
            :crossorigin="useNativeCrossOrigin ? '' : undefined"
        >
            <media-provider>
                <media-poster v-if="posterUrl" class="vds-poster" :src="posterUrl" alt="" />
            </media-provider>
            <media-video-layout
                :translations="vidstackLayoutTranslations"
                :playback-rates="vidstackPlaybackRates"
            >
                <media-airplay-button slot="airPlayButton">
                    <media-icon type="airplay" />
                </media-airplay-button>
                <media-google-cast-button slot="googleCastButton">
                    <media-icon type="chromecast" />
                </media-google-cast-button>
            </media-video-layout>
        </media-player>
        <div
            v-if="watermarkEnabled && watermarkText"
            class="watermark-overlay"
            :class="POSITIONS[watermarkPosition]"
        >
            {{ watermarkText }}
        </div>
    </div>
</template>

<style scoped>
.member-area-video-player {
    --media-brand: var(--ma-primary, #ff0000);
    --media-focus-ring-color: color-mix(in srgb, var(--ma-primary, #ff0000) 60%, white);
    --video-progress-color: var(--ma-primary, #ff0000);
    max-width: 100%;
}
.member-area-video-player.is-immersive {
    position: fixed;
    inset: 0;
    z-index: 100;
    width: 100%;
    height: 100%;
    max-width: none;
    border-radius: 0;
    aspect-ratio: unset;
    display: flex;
    flex-direction: column;
    background: #000;
}
.member-area-video-player.is-immersive .yt-legacy-root {
    flex: 1;
    min-height: 0;
    height: 100%;
}
.member-area-video-player.is-immersive .yt-legacy-mount {
    height: 100%;
}
.member-area-video-player.is-immersive .player {
    flex: 1;
    min-height: 0;
    height: 100%;
}
.exit-immersive-btn {
    position: absolute;
    top: max(10px, env(safe-area-inset-top, 0px));
    right: max(10px, env(safe-area-inset-right, 0px));
    z-index: 120;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 44px;
    width: 44px;
    border-radius: 9999px;
    background: rgba(0, 0, 0, 0.65);
    color: rgba(255, 255, 255, 0.95);
    border: 1px solid rgba(255, 255, 255, 0.25);
}
.exit-immersive-btn:focus-visible {
    outline: 2px solid rgba(78, 156, 246, 0.9);
    outline-offset: 2px;
}
.player {
    width: 100%;
    height: 100%;
    display: block;
}
.player[data-view-type='video'] {
    aspect-ratio: 16 / 9;
}
/* Poster por cima do iframe do YouTube até o usuário dar play */
.player :deep(.vds-poster),
.player :deep([data-media-poster]) {
    z-index: 1;
}
.player :deep(media-provider),
.player :deep([data-media-provider]) {
    z-index: 0;
    overflow: hidden !important;
    position: relative;
}
.player :deep(media-provider iframe),
.player :deep([data-media-provider] iframe) {
    position: absolute;
    top: -10%;
    left: -4%;
    width: 108% !important;
    height: 124% !important;
    max-width: none;
    pointer-events: none;
    border: 0;
}
/* Camada 1: esconder PiP para dificultar gravação */
.player :deep(media-pip-button) {
    display: none !important;
}

.yt-legacy-root,
.yt-legacy-mount,
.yt-legacy-iframe {
    width: 100%;
    height: 100%;
}
.yt-legacy-root {
    position: relative;
    overflow: hidden;
}
.yt-legacy-mount {
    position: absolute;
    inset: 0;
    overflow: hidden;
}
.yt-legacy-mount :deep(iframe) {
    position: absolute;
    top: -10%;
    left: -4%;
    width: 108% !important;
    height: 124% !important;
    max-width: none;
    pointer-events: none;
    border: 0;
    display: block;
}
.yt-pause-cover {
    position: absolute;
    inset: 0;
    z-index: 2;
    background: #000;
    pointer-events: none;
}
.yt-pause-cover-fallback {
    background: #000;
}
.yt-pause-cover-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.yt-mask {
    position: absolute;
    inset: 0;
    z-index: 1;
    background: #000;
}
.yt-poster {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    opacity: 0.98;
}
.yt-loading-overlay {
    position: absolute;
    inset: 0;
    z-index: 4;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.72);
    pointer-events: none;
}
.yt-loading-text {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.9);
}
.yt-veil {
    position: absolute;
    inset: 0;
    z-index: 3;
    background: transparent;
    border: 0;
    padding: 0;
    margin: 0;
    cursor: pointer;
}
.yt-progress-overlay {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 5;
    display: flex;
    align-items: flex-end;
    height: 16px;
    padding: 0 6px;
    cursor: pointer;
    touch-action: none;
    overflow: visible;
    box-sizing: border-box;
}
.member-area-video-player.is-immersive .yt-progress-overlay {
    padding: 0;
}
.yt-progress-track {
    position: relative;
    width: 100%;
    height: 4px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 9999px;
    overflow: hidden;
    transition: height 0.15s ease;
}
.yt-progress-overlay.is-hover .yt-progress-track,
.yt-progress-overlay.is-scrubbing .yt-progress-track {
    height: 6px;
}
.yt-progress-buffer {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.35);
    pointer-events: none;
    transition: width 0.15s linear;
}
.yt-progress-played {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    background: var(--video-progress-color);
    pointer-events: none;
    transition: width 0.08s linear;
    z-index: 1;
}
.yt-progress-thumb {
    position: absolute;
    bottom: -5px;
    left: 0;
    width: 14px;
    height: 14px;
    border-radius: 9999px;
    background: var(--video-progress-color);
    box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.35);
    transform: translateX(-50%) scale(0);
    transition: transform 0.15s ease, bottom 0.15s ease;
    pointer-events: none;
    z-index: 2;
}
.yt-progress-overlay.is-hover .yt-progress-thumb,
.yt-progress-overlay.is-scrubbing .yt-progress-thumb {
    bottom: -4px;
    transform: translateX(-50%) scale(1);
}
.yt-progress-overlay.is-scrubbing .yt-progress-thumb {
    box-shadow:
        0 0 0 1px rgba(0, 0, 0, 0.35),
        0 0 0 4px color-mix(in srgb, var(--video-progress-color) 35%, transparent);
}
.yt-legacy-controls {
    position: absolute;
    left: 10px;
    bottom: 42px;
    z-index: 4;
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: opacity 0.18s ease, transform 0.18s ease;
}
.yt-legacy-controls.hidden {
    opacity: 0;
    transform: translateY(6px);
    pointer-events: none;
}
.yt-controlbar {
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 16px;
    background: rgba(0, 0, 0, 0.55);
    color: rgba(255, 255, 255, 0.92);
    border: 1px solid rgba(255, 255, 255, 0.18);
    backdrop-filter: blur(6px);
}
.yt-icon-btn {
    height: 34px;
    width: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.10);
    border: 1px solid rgba(255, 255, 255, 0.16);
    color: rgba(255, 255, 255, 0.92);
    transition: background 0.15s ease, transform 0.1s ease;
}
.yt-icon-btn:hover {
    background: rgba(255, 255, 255, 0.16);
}
.yt-icon-btn:active {
    transform: scale(0.98);
}
.yt-time {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.86);
    font-variant-numeric: tabular-nums;
    user-select: none;
}
.yt-time-sep {
    opacity: 0.6;
    padding: 0 4px;
}
.yt-volume-wrap {
    display: none;
    align-items: center;
    gap: 2px;
}
@media (min-width: 640px) {
    .yt-volume-wrap {
        display: flex;
    }
}
.yt-volume-slider {
    width: 0;
    overflow: hidden;
    opacity: 0;
    transition: width 0.15s ease, opacity 0.15s ease;
    touch-action: none;
    cursor: pointer;
    padding: 8px 0;
}
.yt-volume-wrap.is-hover .yt-volume-slider,
.yt-volume-wrap.is-scrubbing .yt-volume-slider {
    width: 72px;
    opacity: 1;
}
.yt-volume-track {
    position: relative;
    width: 100%;
    height: 4px;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.28);
    overflow: visible;
}
.yt-volume-fill {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    border-radius: 9999px;
    background: var(--video-progress-color, var(--ma-primary, #ff0000));
    pointer-events: none;
    transition: width 0.08s linear;
}
.yt-volume-thumb {
    position: absolute;
    bottom: -3px;
    left: 0;
    width: 10px;
    height: 10px;
    border-radius: 9999px;
    background: #fff;
    box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.35);
    transform: translateX(-50%) scale(0);
    transition: transform 0.12s ease;
    pointer-events: none;
}
.yt-volume-wrap.is-hover .yt-volume-thumb,
.yt-volume-wrap.is-scrubbing .yt-volume-thumb {
    transform: translateX(-50%) scale(1);
}
.yt-menu-wrap {
    position: relative;
}
.yt-settings-menu {
    pointer-events: auto;
    width: 180px;
    max-height: min(70vh, 280px);
    overflow-y: auto;
    border-radius: 12px;
    background: rgba(0, 0, 0, 0.72);
    border: 1px solid rgba(255, 255, 255, 0.18);
    backdrop-filter: blur(8px);
    padding: 6px;
    display: grid;
    gap: 4px;
    position: absolute;
    right: 0;
    bottom: calc(100% + 10px);
}
.yt-speed-btn {
    width: auto;
    min-width: 34px;
    padding: 0 8px;
    gap: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.yt-speed-btn-label {
    font-size: 11px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    line-height: 1;
    user-select: none;
}
.yt-settings-item {
    width: 100%;
    text-align: left;
    padding: 8px 10px;
    border-radius: 10px;
    color: rgba(255, 255, 255, 0.92);
    font-size: 12px;
    line-height: 1;
    background: transparent;
    border: 1px solid transparent;
}
.yt-settings-item.active {
    background: rgba(255, 255, 255, 0.12);
    border-color: rgba(255, 255, 255, 0.18);
}
.yt-settings-item:focus-visible {
    outline: 2px solid rgba(78, 156, 246, 0.9);
    outline-offset: 1px;
}
.yt-quality-error {
    pointer-events: none;
    font-size: 11px;
    color: rgba(255, 200, 200, 0.95);
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.8);
}
.yt-playlist-warning {
    pointer-events: none;
    font-size: 11px;
    color: rgba(255, 255, 255, 0.7);
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.8);
}
/* Marca d'água: overlay que muda de posição */
.watermark-overlay {
    position: absolute;
    z-index: 5;
    pointer-events: none;
    font-size: clamp(0.75rem, 2vw, 1rem);
    color: rgba(255, 255, 255, 0.6);
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.8);
    transition: left 0.5s, top 0.5s, right 0.5s, bottom 0.5s;
}
.watermark-overlay.top-left {
    left: 8px;
    top: 8px;
}
.watermark-overlay.top-right {
    right: 8px;
    top: 8px;
}
.watermark-overlay.bottom-left {
    left: 8px;
    bottom: 8px;
}
.watermark-overlay.bottom-right {
    right: 8px;
    bottom: 8px;
}
.watermark-overlay.center {
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
}
@media (max-width: 639px) {
    .member-area-video-player.is-immersive {
        width: 100dvw;
        height: 100dvh;
    }
    .yt-legacy-controls {
        left: 8px;
        right: auto;
        max-width: calc(100% - 16px);
        width: max-content;
    }
    .yt-controlbar {
        width: max-content;
        max-width: calc(100% - 16px);
        flex-wrap: nowrap;
        gap: 6px;
        padding: 6px 8px;
    }
    .yt-icon-btn {
        height: 30px;
        width: 30px;
    }
    .yt-time {
        font-size: 11px;
    }
    .yt-speed-btn-label {
        display: none;
    }
    .yt-quality-menu-wrap {
        display: none;
    }
    .yt-progress-overlay {
        height: 22px;
    }
    .yt-progress-overlay.is-scrubbing .yt-progress-track {
        height: 7px;
    }
    .yt-progress-overlay.is-scrubbing .yt-progress-thumb {
        transform: translate(-50%, -50%) scale(1);
    }
}
</style>
