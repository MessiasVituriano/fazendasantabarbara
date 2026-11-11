import { createRouter, createWebHistory } from 'vue-router'
import { inicioDoPerfil, useSessao } from './composables/useSessao'

const SO_GESTOR = { perfis: ['GESTOR'] }

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/entrar', name: 'entrar', component: () => import('./views/EntrarView.vue'), meta: { publica: true } },
    { path: '/fazendas', name: 'fazendas', component: () => import('./views/EscolherFazendaView.vue'), meta: { semFazenda: true } },
    // Sem login: links de convite e de nova senha (valem mesmo com outra sessão aberta no aparelho).
    { path: '/esqueci-senha', name: 'esqueci-senha', component: () => import('./views/EsqueciSenhaView.vue'), meta: { publica: true } },
    { path: '/nova-senha/:token', name: 'nova-senha', component: () => import('./views/NovaSenhaView.vue'), props: true, meta: { publica: true } },
    { path: '/convite/:token', name: 'convite', component: () => import('./views/ConviteView.vue'), props: true, meta: { publica: true } },
    // Administração da plataforma e conta do produtor
    { path: '/admin', name: 'admin', component: () => import('./views/AdminView.vue'), meta: { semFazenda: true, admin: true } },
    { path: '/conta', name: 'conta', component: () => import('./views/ContaView.vue'), meta: { dono: true } },
    { path: '/', redirect: '/financeiro' },
    { path: '/painel', name: 'painel', component: () => import('./views/PainelView.vue'), meta: SO_GESTOR },
    { path: '/animais', name: 'animais', component: () => import('./views/AnimaisView.vue') },
    { path: '/animais/:id', name: 'animal', component: () => import('./views/AnimalView.vue'), props: true },
    { path: '/ordenha', name: 'ordenha', component: () => import('./views/OrdenhaView.vue') },
    { path: '/pesagens', name: 'pesagens', component: () => import('./views/PesagensView.vue') },
    { path: '/sanidade', name: 'sanidade', component: () => import('./views/SanidadeView.vue') },
    { path: '/abate', name: 'abate', component: () => import('./views/AbateView.vue'), meta: SO_GESTOR },
    { path: '/estoque', name: 'estoque', component: () => import('./views/EstoqueView.vue') },
    { path: '/piquetes', name: 'piquetes', component: () => import('./views/PiquetesView.vue') },
    { path: '/perfil', name: 'perfil', component: () => import('./views/PerfilView.vue') },
    // Financeiro e lotes (somente gestor)
    { path: '/financeiro', name: 'financeiro', component: () => import('./views/financeiro/VisaoGeralView.vue'), meta: SO_GESTOR },
    { path: '/financeiro/lancamentos', name: 'lancamentos', component: () => import('./views/financeiro/LancamentosView.vue'), meta: SO_GESTOR },
    { path: '/financeiro/contas-a-pagar-e-receber', name: 'contas-pagar-receber', component: () => import('./views/financeiro/ContasPagarReceberView.vue'), meta: SO_GESTOR },
    { path: '/financeiro/fluxo-de-caixa', name: 'fluxo-caixa', component: () => import('./views/financeiro/FluxoCaixaView.vue'), meta: SO_GESTOR },
    { path: '/financeiro/aportes-e-retiradas', name: 'aportes-retiradas', component: () => import('./views/financeiro/AportesRetiradasView.vue'), meta: SO_GESTOR },
    { path: '/financeiro/cadastros', name: 'cadastros-financeiros', component: () => import('./views/financeiro/CadastrosView.vue'), meta: SO_GESTOR },
    { path: '/lotes', name: 'lotes', component: () => import('./views/LotesView.vue'), meta: SO_GESTOR },
    { path: '/usuarios', name: 'usuarios', component: () => import('./views/UsuariosView.vue'), meta: SO_GESTOR },
    // endereços antigos
    { path: '/manejo', redirect: '/ordenha' },
    { path: '/lancamentos', redirect: '/pesagens' },
    { path: '/:caminho(.*)*', redirect: '/' },
  ],
})

router.beforeEach((to) => {
  const { autenticado, usuario, fazendas, fazendaId, admin, dono } = useSessao()
  // Quem tem mais de uma fazenda escolhe uma antes de entrar nas telas; o administrador sem fazenda fica na administração.
  const precisaEscolher = !fazendaId.value && fazendas.value.length > 1
  const adminSemFazenda = admin.value && !fazendaId.value && fazendas.value.length === 0
  if (to.meta.publica) {
    if (!autenticado.value || to.name !== 'entrar') return true
    if (adminSemFazenda) return { name: 'admin' }
    return precisaEscolher ? { name: 'fazendas' } : inicioDoPerfil(usuario.value.perfil)
  }
  if (!autenticado.value) return { name: 'entrar', query: { volta: to.fullPath } }
  if (to.meta.admin && !admin.value) return inicioDoPerfil(usuario.value.perfil)
  if (to.meta.semFazenda) return true
  if (adminSemFazenda) return { name: 'admin' }
  if (precisaEscolher) return { name: 'fazendas', query: { volta: to.fullPath } }
  if (to.meta.dono && !dono.value) return inicioDoPerfil(usuario.value.perfil)
  // Área restrita: o caseiro vai para o operacional.
  if (to.meta.perfis && !to.meta.perfis.includes(usuario.value.perfil)) return inicioDoPerfil(usuario.value.perfil)
  return true
})

export default router
