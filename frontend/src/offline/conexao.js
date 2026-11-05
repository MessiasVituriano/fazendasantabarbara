import { ref } from 'vue'

/** Estado da conexão do aparelho, atualizado pelos eventos online/offline do navegador. */
export const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine)

if (typeof window !== 'undefined') {
  window.addEventListener('online', () => (online.value = true))
  window.addEventListener('offline', () => (online.value = false))
}

/** Erro de rede (sem resposta do servidor): sem internet, servidor fora do ar ou tempo esgotado. */
export const semConexao = (erro) => !!erro && !erro.response && erro.code !== 'ERR_CANCELED'
