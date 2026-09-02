import { defineComponent, h } from 'vue';

const SettingsTab = defineComponent({
  name: 'StarterSettingsTab',
  setup() {
    return () =>
      h(
        'div',
        { class: 'space-y-2 rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700' },
        [
          h('p', { class: 'font-medium' }, 'Getfy Plugin Starter'),
          h('p', { class: 'text-zinc-500' }, 'Substitua este painel pela configuração do seu plugin. Veja docs/developers/.'),
        ],
      );
  },
});

window.__GETFY_PLUGIN_UI__ = window.__GETFY_PLUGIN_UI__ || {};
window.__GETFY_PLUGIN_UI__['getfy-plugin-starter'] = { SettingsTab };
