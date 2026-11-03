import { computed, ref } from 'vue'
import http from '../api/http'

/**
 * Contas, categorias, pessoas e lotes usados nos formulários do financeiro.
 * Carregados uma vez e compartilhados entre as telas; `recarregar()` após cadastrar algo.
 */
const contas = ref([])
const categorias = ref([])
const pessoas = ref([])
const lotes = ref([])
let carregando = null

async function recarregar() {
  const [c, cat, p, l] = await Promise.all([
    http.get('/financeiro/contas'),
    http.get('/financeiro/categorias'),
    http.get('/pessoas'),
    http.get('/lotes', { params: { incluir_encerrados: 1 } }),
  ])
  contas.value = c.data.data
  categorias.value = cat.data.data
  pessoas.value = p.data.data
  lotes.value = l.data.data
}

export function useCadastrosFinanceiros() {
  if (!carregando) carregando = recarregar().catch((e) => { carregando = null; throw e })
  return {
    contas,
    categorias,
    pessoas,
    lotes,
    pronto: carregando,
    recarregar: () => (carregando = recarregar()),
    contasAtivas: computed(() => contas.value.filter((c) => c.ativa)),
    lotesAtivos: computed(() => lotes.value.filter((l) => !l.data_encerramento)),
    categoriasDo: (tipo) => categorias.value.filter((c) => c.tipo === tipo && c.ativa),
  }
}
