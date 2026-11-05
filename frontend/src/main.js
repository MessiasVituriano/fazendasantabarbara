import { createApp, watch } from 'vue'
import App from './App.vue'
import router from './router'
import { useSessao } from './composables/useSessao'
import { aquecerCache } from './offline/aquecer'
import { online } from './offline/conexao'
import { iniciarSincronizacao } from './offline/fila'
import './style.css'

createApp(App).use(router).mount('#app')

// Registro offline: fila de envio + dados de manejo guardados no aparelho.
iniciarSincronizacao()
watch(online, (agora) => agora && aquecerCache())
watch(() => useSessao().usuario.value?.id, (id) => id && aquecerCache({ forcar: true }), { immediate: true })

// Service worker: guarda o app (HTML, JS, CSS) para abrir sem internet. Só no build de produção.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}))
}
