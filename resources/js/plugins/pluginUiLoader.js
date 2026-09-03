import { defineAsyncComponent, h } from 'vue';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';

/** @type {Map<string, Promise<void>>} */
const scriptLoadPromises = new Map();

/** @type {Map<string, Record<string, unknown>>} */
const pluginExportRegistry = new Map();

/**
 * @param {string} entryUrl
 * @param {string} slug
 */
function pluginHasExport(slug, exportName) {
    return Boolean(window.__GETFY_PLUGIN_UI__?.[slug]?.[exportName]);
}

function removePluginScript(slug) {
    document.querySelector(`script[data-plugin-ui="${slug}"]`)?.remove();
    scriptLoadPromises.delete(slug);
}

function loadPluginScript(entryUrl, slug, exportName = null) {
    const key = slug;
    if (exportName && pluginHasExport(slug, exportName)) {
        return Promise.resolve();
    }
    if (scriptLoadPromises.has(key)) {
        return scriptLoadPromises.get(key);
    }
    const promise = new Promise((resolve, reject) => {
        if (!entryUrl) {
            reject(new Error('URL do bundle do plugin ausente'));
            return;
        }
        const existing = document.querySelector(`script[data-plugin-ui="${slug}"]`);
        if (existing) {
            if (exportName && !pluginHasExport(slug, exportName)) {
                existing.remove();
                scriptLoadPromises.delete(key);
            } else {
                resolve();
                return;
            }
        }
        const script = document.createElement('script');
        script.type = 'module';
        script.src = `${entryUrl}${entryUrl.includes('?') ? '&' : '?'}v=${Date.now()}`;
        script.async = true;
        script.dataset.pluginUi = slug;
        script.onload = () => resolve();
        script.onerror = () => {
            removePluginScript(slug);
            reject(new Error(`Falha ao carregar UI do plugin "${slug}"`));
        };
        document.head.appendChild(script);
    });
    scriptLoadPromises.set(key, promise);

    return promise;
}

async function waitForPluginExport(slug, exportName, attempts = 40) {
    for (let i = 0; i < attempts; i += 1) {
        const loaded = window.__GETFY_PLUGIN_UI__?.[slug]?.[exportName];
        if (loaded) {
            return loaded;
        }
        await new Promise((resolve) => setTimeout(resolve, 50));
    }

    return null;
}

/**
 * Plugins registram exports no bundle via window.__GETFY_PLUGIN_UI__[slug].
 *
 * @param {string} slug
 * @param {string} exportName
 */
export function registerPluginUiExport(slug, exportName, component) {
    if (!slug || !exportName) {
        return;
    }
    const bucket = pluginExportRegistry.get(slug) ?? {};
    bucket[exportName] = component;
    pluginExportRegistry.set(slug, bucket);
    if (typeof window !== 'undefined') {
        window.__GETFY_PLUGIN_UI__ = window.__GETFY_PLUGIN_UI__ || {};
        window.__GETFY_PLUGIN_UI__[slug] = window.__GETFY_PLUGIN_UI__[slug] || {};
        window.__GETFY_PLUGIN_UI__[slug][exportName] = component;
    }
}

/**
 * @param {import('vue').App} app
 */
export function installPluginUiBridge(app) {
    if (typeof window === 'undefined') {
        return;
    }
    window.__GETFY_REGISTER_PLUGIN_UI__ = (slug, exportName, component) => {
        registerPluginUiExport(slug, exportName, component);
    };
}

/**
 * @param {{ slug: string, entry?: string|null, exports?: string[] }} pluginMeta
 * @param {string} exportName
 */
export async function ensurePluginUiLoaded(pluginMeta, exportName) {
    const slug = pluginMeta?.slug;
    if (!slug || !exportName) {
        throw new Error('Plugin ou export inválido');
    }
    const fromWindow = window.__GETFY_PLUGIN_UI__?.[slug]?.[exportName];
    if (fromWindow) {
        return fromWindow;
    }
    const cached = pluginExportRegistry.get(slug)?.[exportName];
    if (cached) {
        return cached;
    }
    await loadPluginScript(pluginMeta.entry, slug, exportName);
    let loaded = await waitForPluginExport(slug, exportName);
    if (!loaded) {
        removePluginScript(slug);
        await loadPluginScript(pluginMeta.entry, slug, exportName);
        loaded = await waitForPluginExport(slug, exportName);
    }
    if (!loaded) {
        throw new Error(`Export "${exportName}" não encontrado no plugin "${slug}"`);
    }

    return loaded;
}

/**
 * @param {{ plugin_slug?: string, ui_mode?: string, ui_export?: string|null, component?: string }} slotItem
 * @param {Record<string, { slug: string, entry?: string|null }>} pluginUiBySlug
 */
export function resolvePluginSlotComponent(slotItem, pluginUiBySlug) {
    if (!slotItem) {
        return null;
    }
    if (slotItem.ui_mode === 'runtime' && slotItem.plugin_slug && slotItem.ui_export) {
        const meta = pluginUiBySlug[slotItem.plugin_slug];
        if (!meta?.entry) {
            return PluginUiError(slotItem.plugin_slug, 'Bundle do plugin não configurado');
        }
        return defineAsyncComponent({
            loader: async () => ensurePluginUiLoaded(meta, slotItem.ui_export),
            errorComponent: PluginUiError(slotItem.plugin_slug, 'Erro ao carregar UI do plugin'),
            timeout: 30000,
        });
    }

    return null;
}

function PluginUiError(slug, message) {
    return {
        name: 'PluginUiError',
        props: { message: { type: String, default: message } },
        setup(props) {
            return () =>
                h(
                    'div',
                    {
                        class: 'rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100',
                        role: 'alert',
                    },
                    props.message || `Não foi possível carregar o plugin "${slug}".`,
                );
        },
    };
}

/**
 * @param {Record<string, { slug: string, entry?: string|null, frontend_exports_map?: Record<string, string> }>} pluginUiBySlug
 */
/**
 * Resolve página Inertia Plugin/{slug}/{Page} via runtime dist/.
 *
 * @param {string} componentName
 * @param {Record<string, unknown>|null} pluginUiPayload
 */
export function resolvePluginPageComponent(componentName, pluginUiPayload) {
    if (!componentName?.startsWith?.('Plugin/')) {
        return null;
    }
    const rel = componentName.slice(7);
    const slash = rel.indexOf('/');
    if (slash <= 0) {
        return null;
    }
    const slug = rel.slice(0, slash);
    const page = rel.slice(slash + 1) || 'Index';
    const bySlug = buildPluginUiIndex(pluginUiPayload);
    const meta = bySlug[slug];
    const pages = meta?.frontend_pages ?? {};
    const exportName = pages[page];
    if (!exportName || !meta?.entry) {
        return null;
    }

    const AsyncContent = defineAsyncComponent({
        loader: () => ensurePluginUiLoaded(meta, exportName),
        errorComponent: PluginUiError(slug, `Erro ao carregar página "${page}" do plugin`),
        timeout: 30000,
    });

    const Page = {
        name: `PluginRuntimePage_${slug}_${page}`,
        props: {
            pluginSlug: { type: String, default: '' },
            pluginPage: { type: String, default: '' },
            plugin_ui_page: { type: Object, default: null },
        },
        setup(props) {
            return () => h(AsyncContent, props);
        },
    };
    Page.layout = LayoutInfoprodutor;

    return Page;
}

export function getPluginUiPayloadFromDom() {
    try {
        const data = document.getElementById('app')?.getAttribute('data-page');
        const page = data ? JSON.parse(data) : null;

        return page?.props?.plugin_ui ?? null;
    } catch {
        return null;
    }
}

export function buildPluginUiIndex(pluginUiPayload) {
    const list = pluginUiPayload?.plugins ?? [];
    /** @type {Record<string, { slug: string, entry?: string|null }>} */
    const bySlug = {};
    for (const p of list) {
        if (p?.slug) {
            bySlug[p.slug] = p;
        }
    }

    return bySlug;
}
