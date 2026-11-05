import { computed, ref } from 'vue'
import http from '../api/http'

/**
 * Categorias e produtos de vacinas e medicamentos, compartilhados entre as telas.
 * Carregados uma vez; `recarregar()` após cadastrar algo.
 */
const categorias = ref([])
const produtos = ref([])
let carregando = null

async function recarregar() {
  const [c, p] = await Promise.all([http.get('/sanidade/categorias'), http.get('/sanidade/produtos')])
  categorias.value = c.data.data
  produtos.value = p.data.data
}

export function useCadastrosSanitarios() {
  if (!carregando) carregando = recarregar().catch((e) => { carregando = null; throw e })
  return {
    categorias,
    produtos,
    pronto: carregando,
    recarregar: () => (carregando = recarregar()),
    categoriasAtivas: computed(() => categorias.value.filter((c) => c.ativa)),
    // Desativar uma categoria também tira os produtos dela das novas aplicações.
    produtosAtivos: computed(() => produtos.value.filter((p) => p.ativo && categorias.value.some((c) => c.id === p.categoria_sanitaria_id && c.ativa))),
  }
}
