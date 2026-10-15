import { computed } from 'vue'
import { useOpcoes } from './useOpcoes'
import { useSessao } from './useSessao'

/** Data mais antiga que o usuário pode lançar: o caseiro tem limite de N dias; o gestor não. */
export function useDataMinima() {
  const { opcoes } = useOpcoes()
  const { ehGestor } = useSessao()
  return computed(() => {
    const dias = opcoes.value?.pecuaria?.dias_retroativos_caseiro
    if (ehGestor.value || dias == null) return undefined
    const d = new Date()
    d.setDate(d.getDate() - dias)
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
  })
}
