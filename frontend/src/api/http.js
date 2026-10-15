import axios from 'axios'
import { useSessao } from '../composables/useSessao'

const http = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: { Accept: 'application/json' },
})

const sessao = useSessao()

http.interceptors.request.use((config) => {
  if (sessao.token.value) config.headers.Authorization = `Bearer ${sessao.token.value}`
  return config
})

/**
 * Normaliza erros da API (padrão JSON API) para uso direto nos formulários:
 *   erro.mensagens -> lista de textos legíveis
 *   erro.campos    -> { 'registros.0.animal_id': 'mensagem', ... } a partir de source.pointer
 * 401 (sessão expirada/encerrada) leva de volta ao login.
 */
http.interceptors.response.use(
  (resposta) => resposta,
  (erro) => {
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
