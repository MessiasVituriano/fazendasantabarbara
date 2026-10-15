import { ref } from 'vue'

// Estado compartilhado do menu móvel (gaveta), aberto pelo botão do cabeçalho.
const aberto = ref(false)

export function useMenu() {
  return { aberto }
}
