import { ref } from 'vue'
import http from '../api/http'

/**
 * Nome do produto, vindo da API (GET /sistema, público). Fica guardado no aparelho para a tela
 * de login e o título da aba abrirem certos sem internet.
 */
const CHAVE = 'sistema.produto'
const PADRAO = 'Gestão Agropecuária'
const lerGuardado = () => { try { return localStorage.getItem(CHAVE) } catch { return null } }
const produto = ref(lerGuardado() || PADRAO)
let carregado = false

export function carregarSistema() {
  if (carregado) return
  carregado = true
  http.get('/sistema').then(({ data }) => {
    if (!data?.data?.produto) return
    produto.value = data.data.produto
    try { localStorage.setItem(CHAVE, produto.value) } catch { /* sem storage */ }
  }).catch(() => { carregado = false })
}

export function useSistema() {
  carregarSistema()
  return { produto }
}
