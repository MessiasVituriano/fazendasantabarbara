import axios from 'axios'
import { useSessao } from '../composables/useSessao'
import { guardarLeitura, lerLeitura } from '../offline/cache'
import { semConexao } from '../offline/conexao'

const http = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: { Accept: 'application/json' },
})

const sessao = useSessao()

http.interceptors.request.use((config) => {
  if (sessao.token.value) config.headers.Authorization = `Bearer ${sessao.token.value}`
  return config
})

const ehLeitura = (config) => config?.method === 'get' && (!config.responseType || config.responseType === 'json')
const chaveDaLeitura = (config) => http.getUri(config)

/**
 * Leituras (GET) bem-sucedidas ficam guardadas no aparelho; sem conexão, a última resposta
 * guardada é devolvida (com `resposta.offline = true` e `resposta.guardadaEm`).
 *
 * Normaliza erros da API (padrão JSON API) para uso direto nos formulários:
 *   erro.mensagens -> lista de textos legíveis
 *   erro.campos    -> { 'registros.0.animal_id': 'mensagem', ... } a partir de source.pointer
 * 401 (sessão expirada/encerrada) leva de volta ao login.
 */
http.interceptors.response.use(
  (resposta) => {
    if (ehLeitura(resposta.config) && resposta.data && typeof resposta.data === 'object') {
      guardarLeitura(chaveDaLeitura(resposta.config), resposta.data)
    }
    return resposta
  },
  (erro) => {
    if (semConexao(erro) && ehLeitura(erro.config)) {
      const guardada = lerLeitura(chaveDaLeitura(erro.config))
      if (guardada) {
        return { data: guardada.dados, status: 200, statusText: 'OK', headers: {}, config: erro.config, offline: true, guardadaEm: guardada.em }
      }
    }
    const erros = erro.response?.data?.errors
    if (Array.isArray(erros)) {
      erro.mensagens = erros.map((e) => e.detail || e.title)
      erro.campos = Object.fromEntries(
        erros.filter((e) => e.source?.pointer).map((e) => [e.source.pointer.replace(/^\//, '').replaceAll('/', '.'), e.detail]),
      )
    } else {
      erro.mensagens = [erro.response ? 'Erro inesperado no servidor.' : 'Sem conexão com o servidor.']
      erro.campos = {}
    }
    if (erro.response?.status === 401 && !erro.config?.url?.endsWith('/login')) {
      sessao.encerrar()
      const volta = encodeURIComponent(location.pathname + location.search)
      if (!location.pathname.startsWith('/entrar')) location.assign(`/entrar?volta=${volta}`)
    }
    return Promise.reject(erro)
  },
)

export default http
