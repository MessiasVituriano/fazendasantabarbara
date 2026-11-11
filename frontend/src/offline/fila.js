import { computed, ref, watch } from 'vue'
import { toast } from 'vue-sonner'
import http, { CABECALHO_FAZENDA } from '../api/http'
import { useSessao } from '../composables/useSessao'
import { novoUuid } from '../formatos'
import { online, semConexao } from './conexao'

/**
 * Fila de registros feitos sem internet, guardada no localStorage deste aparelho.
 *
 * Cada item é uma requisição de gravação (método, endereço e corpo) já pronta para a API.
 * Os endpoints usados são idempotentes (uuid por registro ou "salvar turno"), então reenviar
 * um item que chegou ao servidor mas cuja resposta se perdeu não duplica nada.
 *
 * A sincronização roda ao voltar a conexão, ao abrir o app, ao voltar para a aba e a cada
 * minuto enquanto houver pendências. Os itens vão em ordem; um erro de rede interrompe a
 * rodada. Recusas do servidor (ex.: validação) ficam marcadas com a mensagem para o usuário
 * revisar e descartar, sem travar o restante da fila.
 *
 * Cada item sobe para a fazenda em que foi registrado, mesmo que o usuário tenha trocado de fazenda
 * antes de sincronizar. Itens antigos, sem fazenda, sobem sem o cabeçalho: a API usa a única fazenda do usuário.
 *
 * Item: { id, usuarioId, fazendaId, fazendaNome, metodo, url, dados, descricao, criadoEm, estado: 'pendente'|'erro', erros: [] }
 */
const CHAVE = 'fila-offline'
const TEMPO_LIMITE_MS = 20000

function ler() {
  try { return JSON.parse(localStorage.getItem(CHAVE)) ?? [] } catch { return [] }
}
const itens = ref(ler())

watch(itens, (valor) => {
  try { localStorage.setItem(CHAVE, JSON.stringify(valor)) } catch {
    toast.error('Sem espaço no aparelho para guardar registros offline.')
  }
}, { deep: true })

// Outra aba do mesmo aparelho alterou a fila.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (e) => { if (e.key === CHAVE) itens.value = ler() })
}

const sessao = useSessao()
const sincronizando = ref(false)

/** Itens do usuário logado: a fila de outro usuário no mesmo aparelho só sobe no login dele. */
const doUsuario = computed(() => itens.value.filter((i) => i.usuarioId === sessao.usuario.value?.id))
const pendentes = computed(() => doUsuario.value.filter((i) => i.estado === 'pendente'))
const comErro = computed(() => doUsuario.value.filter((i) => i.estado === 'erro'))

const requisitar = (item) => http.request({
  method: item.metodo, url: item.url, data: item.dados, timeout: TEMPO_LIMITE_MS, fila: true,
  headers: item.fazendaId ? { [CABECALHO_FAZENDA]: item.fazendaId } : {},
})

function guardar({ metodo, url, dados, descricao }) {
  const item = {
    id: novoUuid(), usuarioId: sessao.usuario.value?.id ?? null,
    fazendaId: sessao.fazendaId.value, fazendaNome: sessao.fazendaAtual.value?.nome ?? null,
    metodo, url, dados, descricao, criadoEm: new Date().toISOString(), estado: 'pendente', erros: [],
  }
  itens.value.push(item)
  return item
}

/**
 * Envia os pendentes do usuário logado, em ordem.
 * @returns {Promise<{ enviados: number, recusados: number, restantes: number }>}
 */
async function sincronizar({ silencioso = false } = {}) {
  if (sincronizando.value || !sessao.autenticado.value) return { enviados: 0, recusados: 0, restantes: pendentes.value.length }
  sincronizando.value = true
  let enviados = 0
  let recusados = 0
  try {
    for (const item of [...pendentes.value]) {
      try {
        await requisitar(item)
        itens.value = itens.value.filter((i) => i.id !== item.id)
        enviados++
      } catch (erro) {
        if (semConexao(erro)) break
        const status = erro.response?.status
        if (status === 401 || status >= 500 || status === 429) break // tenta de novo mais tarde
        const alvo = itens.value.find((i) => i.id === item.id)
        if (alvo) Object.assign(alvo, { estado: 'erro', erros: erro.mensagens ?? [String(erro)] })
        recusados++
      }
    }
  } finally {
    sincronizando.value = false
  }
  if (!silencioso) {
    if (enviados) toast.success(`${enviados} registro(s) feito(s) offline sincronizado(s)`)
    if (recusados) toast.error(`${recusados} registro(s) offline recusado(s) pelo servidor`, { description: 'Abra o indicador de sincronização para revisar.' })
  }
  return { enviados, recusados, restantes: pendentes.value.length }
}

/**
 * Grava agora, se houver conexão; sem conexão, guarda o registro na fila do aparelho.
 * Erros do servidor (validação etc.) são repassados ao formulário normalmente.
 * Havendo pendências, elas sobem antes, para que a ordem dos registros se mantenha.
 *
 * @returns {Promise<{ offline: boolean, resposta?: import('axios').AxiosResponse }>}
 */
async function enviarOuGuardar({ metodo, url, dados, descricao }) {
  if (online.value && pendentes.value.length) await sincronizar({ silencioso: true })
  if (!online.value || pendentes.value.length) {
    guardar({ metodo, url, dados, descricao })
    return { offline: true }
  }
  try {
    return { offline: false, resposta: await http.request({ method: metodo, url, data: dados, timeout: TEMPO_LIMITE_MS }) }
  } catch (erro) {
    if (!semConexao(erro)) throw erro
    guardar({ metodo, url, dados, descricao })
    return { offline: true }
  }
}

const descartar = (id) => { itens.value = itens.value.filter((i) => i.id !== id) }
function tentarDeNovo(id) {
  const item = itens.value.find((i) => i.id === id)
  if (item) Object.assign(item, { estado: 'pendente', erros: [] })
  return sincronizar()
}

// Gatilhos automáticos de sincronização.
let iniciada = false
function iniciarSincronizacao() {
  if (iniciada || typeof window === 'undefined') return
  iniciada = true
  const tentar = () => online.value && pendentes.value.length && sincronizar()
  watch(online, (agora, antes) => {
    if (agora === antes) return
    if (agora) {
      toast.success('Conexão restabelecida')
      tentar()
    } else {
      toast.warning('Sem internet', { description: 'Os registros de manejo ficam guardados neste aparelho e sobem quando a conexão voltar.' })
    }
  })
  watch(() => sessao.usuario.value?.id, tentar) // login
  document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && tentar())
  setInterval(tentar, 60000)
  tentar()
}

/** Toast padrão para um registro guardado offline. */
export const avisarGuardadoOffline = (oQue) => toast.info(`${oQue} guardado(a) no aparelho`, {
  description: 'Sem internet agora. O envio é automático assim que a conexão voltar.',
})

export function useFilaOffline() {
  return {
    online, itens: doUsuario, pendentes, comErro, sincronizando,
    sincronizar, enviarOuGuardar, descartar, tentarDeNovo,
  }
}

export { enviarOuGuardar, iniciarSincronizacao, sincronizar }
