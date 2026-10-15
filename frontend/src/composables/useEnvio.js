import { ref } from 'vue'

/** Estado de envio de formulário: carregando, erros gerais e erros por campo (JSON API). */
export function useEnvio() {
  const enviando = ref(false)
  const mensagens = ref([])
  const campos = ref({})

  async function enviar(acao) {
    enviando.value = true
    mensagens.value = []
    campos.value = {}
    try {
      return await acao()
    } catch (erro) {
      mensagens.value = erro.mensagens ?? [String(erro)]
      campos.value = erro.campos ?? {}
      throw erro
    } finally {
      enviando.value = false
    }
  }

  return { enviando, mensagens, campos, enviar }
}
