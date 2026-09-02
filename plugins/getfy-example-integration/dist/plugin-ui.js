import { defineComponent, h } from 'vue';

const IntegrationPanel = defineComponent({
  name: 'ExampleIntegrationPanel',
  setup() {
    return () =>
      h('div', { class: 'p-4 text-sm space-y-2' }, [
        h('p', { class: 'font-medium' }, 'Example Integration'),
        h('p', { class: 'text-zinc-500' }, 'Salve webhook_url na config do plugin (Getfy::config()).'),
      ]);
  },
});

window.__GETFY_PLUGIN_UI__ = window.__GETFY_PLUGIN_UI__ || {};
window.__GETFY_PLUGIN_UI__['getfy-example-integration'] = { IntegrationPanel };
