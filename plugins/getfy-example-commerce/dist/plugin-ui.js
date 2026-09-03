import { defineComponent, h } from 'vue';

const SettingsTab = defineComponent({
  name: 'ExampleCommerceSettings',
  setup() {
    return () =>
      h('div', { class: 'p-4 text-sm space-y-1' }, [
        h('p', { class: 'font-medium' }, 'Example Commerce'),
        h('p', { class: 'text-zinc-500' }, 'Getfy::commerce()->catalog() / startCheckout()'),
      ]);
  },
});

window.__GETFY_PLUGIN_UI__ = window.__GETFY_PLUGIN_UI__ || {};
window.__GETFY_PLUGIN_UI__['getfy-example-commerce'] = { SettingsTab };
