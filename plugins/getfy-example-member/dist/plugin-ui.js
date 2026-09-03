import { defineComponent, h } from 'vue';

const MemberPanel = defineComponent({
  name: 'ExampleMemberPanel',
  setup() {
    return () =>
      h('div', { class: 'rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700' }, 'Example Member Panel');
  },
});

window.__GETFY_PLUGIN_UI__ = window.__GETFY_PLUGIN_UI__ || {};
window.__GETFY_PLUGIN_UI__['getfy-example-member'] = { MemberPanel };
