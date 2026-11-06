import { computed, ref } from 'vue'

/**
 * Sessão do usuário (token Sanctum + dados do usuário), guardada no navegador.
 * Perfis: GESTOR (acesso geral) e CASEIRO (operacional).
 */
const CHAVE = 'sessao'
const ler = () => { try { return JSON.parse(localStorage.getItem(CHAVE)) } catch { return null } }
const sessao = ref(ler())

function salvar(valor) {
  sessao.value = valor
  try {
    if (valor) localStorage.setItem(CHAVE, JSON.stringify(valor))
    else localStorage.removeItem(CHAVE)
  } catch { /* sem storage: a sessão vale só nesta aba */ }
}

export function useSessao() {
  const usuario = computed(() => sessao.value?.usuario ?? null)
  const ehGestor = computed(() => usuario.value?.perfil === 'GESTOR')
  return {
    token: computed(() => sessao.value?.token ?? null),
    usuario,
    ehGestor,
    autenticado: computed(() => !!sessao.value?.token),
    iniciar: (token, dadosUsuario) => salvar({ token, usuario: dadosUsuario }),
    atualizarUsuario: (dadosUsuario) => sessao.value && salvar({ ...sessao.value, usuario: dadosUsuario }),
    encerrar: () => salvar(null),
  }
}

/** Página inicial de cada perfil. */
export const inicioDoPerfil = (perfil) => (perfil === 'GESTOR' ? '/financeiro' : '/ordenha')
