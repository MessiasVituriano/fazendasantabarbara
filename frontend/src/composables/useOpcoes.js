import { ref } from 'vue'
import http from '../api/http'

// Enums e parâmetros vindos de GET /api/opcoes, carregados uma única vez.
const opcoes = ref(null)
let carregando = null

export function useOpcoes() {
  if (!opcoes.value && !carregando) {
    carregando = http.get('/opcoes').then(({ data }) => (opcoes.value = data.data))
      .finally(() => (carregando = null))
  }
  const rotulo = (grupo, valor) => opcoes.value?.[grupo]?.find((o) => o.value === valor)?.label ?? valor
  return { opcoes, rotulo }
}
