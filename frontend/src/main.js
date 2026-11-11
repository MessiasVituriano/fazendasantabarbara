import { createApp, watch, watchEffect } from 'vue'
import App from './App.vue'
import router from './router'
import http from './api/http'
import { useSessao } from './composables/useSessao'
import { useSistema } from './composables/useSistema'
import { aquecerCache } from './offline/aquecer'
import { online } from './offline/conexao'
import { iniciarSincronizacao } from './offline/fila'
import './style.css'

createApp(App).use(router).mount('#app')

// Registro offline: fila de envio + dados de manejo guardados no aparelho.
iniciarSincronizacao()
watch(online, (agora) => agora && aquecerCache())
watch(() => [useSessao().usuario.value?.id, useSessao().fazendaId.value], ([id]) => id && aquecerCache({ forcar: true }), { immediate: true })

// Lista de fazendas e perfil sempre em dia (acesso dado ou retirado; sessões abertas antes da multipropriedade).
const sessao = useSessao()
watch(() => [sessao.autenticado.value, online.value], ([autenticado, conectado]) => {
  if (!autenticado || !conectado) return
  http.get('/eu').then(({ data }) => {
    sessao.atualizarFazendas(data.fazendas ?? [])
    if (sessao.fazendaId.value === data.fazenda_atual) sessao.atualizarUsuario(data.data)
  }).catch(() => { /* segue com a sessão guardada */ })
}, { immediate: true })

// Título da aba: fazenda ativa e produto.
const { produto } = useSistema()
watchEffect(() => {
  document.title = sessao.fazendaAtual.value ? `${sessao.fazendaAtual.value.nome} · ${produto.value}` : produto.value
})

// Service worker: guarda o app (HTML, JS, CSS) para abrir sem internet. Só no build de produção.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}))
}
