import http from '../api/http'
import { useSessao } from '../composables/useSessao'
import { hoje } from '../formatos'
import { online } from './conexao'

/**
 * Pré-carrega no cache do aparelho os dados que as telas de manejo precisam para funcionar
 * sem internet, mesmo que o usuário ainda não as tenha aberto. Os parâmetros precisam ser
 * os mesmos usados pelas telas, pois a chave do cache é o endereço completo da requisição.
 */
const INTERVALO_MS = 10 * 60 * 1000
let ultimo = 0

const diaAnterior = (d) => { const x = new Date(`${d}T12:00:00`); x.setDate(x.getDate() - 1); return x.toISOString().slice(0, 10) }

export async function aquecerCache({ forcar = false } = {}) {
  const { autenticado, fazendaId, fazendas } = useSessao()
  // Quem tem várias fazendas só pré-carrega depois de escolher uma (o cache é por fazenda).
  if (!online.value || !autenticado.value || (!fazendaId.value && fazendas.value.length > 1)) return
  if (!forcar && Date.now() - ultimo < INTERVALO_MS) return
  ultimo = Date.now()

  const dia = hoje()
  const turnos = [dia, diaAnterior(dia)].flatMap((d) => ['MANHA', 'TARDE'].map((periodo) => (
    ['/producoes-leite', { params: { data_inicio: d, data_fim: d, periodo, por_pagina: 500 } }]
  )))
  const leituras = [
    ['/opcoes'],
    ['/manejo/ordenha'],                                                       // Ordenha
    ['/animais', { params: { page: 1, por_pagina: 24, status_geral: 'ATIVO' } }], // Rebanho
    ['/animais', { params: { por_pagina: 200 } }],                              // Sanidade e piquetes
    ['/animais', { params: { aptidao: ['CORTE', 'MISTA'], por_pagina: 200 } }], // Pesagens
    ['/pesagens', { params: { por_pagina: 500 } }],
    ['/sanidade/categorias'],
    ['/sanidade/produtos'],
    ['/estoque/itens'],                                                         // Estoque
    ...turnos,
  ]
  await Promise.allSettled(leituras.map(([url, config]) => http.get(url, config)))
}
