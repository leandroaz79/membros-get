/**
 * Única cópia de Vue carregada no painel. Plugins (dist/plugin-ui.js) devem
 * externalizar "vue" e resolver via import map → este módulo.
 *
 * Importa via vue-runtime-internal (alias Vite) para evitar dependência circular
 * quando o import map aponta "vue" para este arquivo no navegador.
 */
import * as Vue from 'vue-runtime-internal';

if (typeof window !== 'undefined') {
    window.__GETFY_VUE__ = Vue;
}

export * from 'vue-runtime-internal';
