import { computed, ref } from 'vue'

/**
 * Sessão do usuário, guardada no navegador: token Sanctum, dados do usuário, as fazendas em que ele
 * pode entrar (com o perfil em cada uma) e a fazenda ativa. O perfil vale por fazenda:
 * GESTOR (acesso geral) e CASEIRO (operacional).
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

const fazendas = computed(() => sessao.value?.fazendas ?? [])
const fazendaId = computed(() => sessao.value?.fazendaId ?? null)
const fazendaAtual = computed(() => fazendas.value.find((f) => f.id === fazendaId.value) ?? null)
const usuario = computed(() => {
  const u = sessao.value?.usuario
  if (!u) return null
  // Perfil da fazenda ativa (o do login só vale quando o usuário tem uma fazenda).
  return fazendaAtual.value ? { ...u, perfil: fazendaAtual.value.perfil, perfil_label: fazendaAtual.value.perfil_label } : u
})

/** Com uma fazenda só, ela é a ativa; com várias, mantém a escolhida se ainda estiver na lista. */
function fazendaPadrao(lista, atual) {
  if (lista.some((f) => f.id === atual)) return atual
  return lista.length === 1 ? lista[0].id : null
}

export function useSessao() {
  return {
    token: computed(() => sessao.value?.token ?? null),
    usuario,
    ehGestor: computed(() => usuario.value?.perfil === 'GESTOR'),
    autenticado: computed(() => !!sessao.value?.token),
    fazendas,
    fazendaId,
    fazendaAtual,
    iniciar: (token, dadosUsuario, listaFazendas = []) => salvar({
      token, usuario: dadosUsuario, fazendas: listaFazendas, fazendaId: fazendaPadrao(listaFazendas, null),
    }),
    /** Dados vindos da API para a fazenda ativa (o perfil recebido é o desta fazenda). */
    atualizarUsuario: (dadosUsuario) => {
      if (!sessao.value) return
      const lista = fazendas.value.map((f) => (f.id === fazendaId.value && dadosUsuario.perfil
        ? { ...f, perfil: dadosUsuario.perfil, perfil_label: dadosUsuario.perfil_label }
        : f))
      salvar({ ...sessao.value, usuario: dadosUsuario, fazendas: lista })
    },
    atualizarFazendas: (lista) => sessao.value && salvar({ ...sessao.value, fazendas: lista, fazendaId: fazendaPadrao(lista, fazendaId.value) }),
    escolherFazenda: (id) => sessao.value && salvar({ ...sessao.value, fazendaId: id }),
    esquecerFazenda: () => sessao.value && salvar({ ...sessao.value, fazendaId: null }),
    encerrar: () => salvar(null),
  }
}

/** Página inicial de cada perfil. */
export const inicioDoPerfil = (perfil) => (perfil === 'GESTOR' ? '/financeiro' : '/ordenha')
