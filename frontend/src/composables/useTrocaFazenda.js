import { inicioDoPerfil, useSessao } from './useSessao'

/**
 * Entra na fazenda escolhida. Recarrega o app (em vez de só navegar) para nenhuma tela ou cadastro
 * guardado em memória continuar mostrando dados da fazenda anterior. As leituras guardadas no aparelho
 * são separadas por fazenda e os registros da fila offline sobem para a fazenda em que foram feitos.
 *
 * @param {number} id
 * @param {string|null} volta Endereço para abrir depois (padrão: início do perfil nesta fazenda).
 */
export function entrarNaFazenda(id, volta = null) {
  const { fazendas, escolherFazenda } = useSessao()
  const fazenda = fazendas.value.find((f) => f.id === id)
  if (!fazenda) return
  escolherFazenda(id)
  window.location.assign(volta ?? inicioDoPerfil(fazenda.perfil))
}
