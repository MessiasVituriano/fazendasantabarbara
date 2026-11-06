import { createRouter, createWebHistory } from 'vue-router'
import { inicioDoPerfil, useSessao } from './composables/useSessao'

const SO_GESTOR = { perfis: ['GESTOR'] }

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/entrar', name: 'entrar', component: () => import('./views/EntrarView.vue'), meta: { publica: true } },
    { path: '/', redirect: '/financeiro' },
    { path: '/painel', name: 'painel', component: () => import('./views/PainelView.vue'), meta: SO_GESTOR },
    { path: '/animais', name: 'animais', component: () => import('./views/AnimaisView.vue') },
    { path: '/animais/:id', name: 'animal', component: () => import('./views/AnimalView.vue'), props: true },
    { path: '/ordenha', name: 'ordenha', component: () => import('./views/OrdenhaView.vue') },
    { path: '/pesagens', name: 'pesagens', component: () => import('./views/PesagensView.vue') },
    { path: '/sanidade', name: 'sanidade', component: () => import('./views/SanidadeView.vue') },
    { path: '/abate', name: 'abate', component: () => import('./views/AbateView.vue'), meta: SO_GESTOR },
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
  const { autenticado, usuario } = useSessao()
  if (to.meta.publica) return autenticado.value && to.name === 'entrar' ? inicioDoPerfil(usuario.value.perfil) : true
  if (!autenticado.value) return { name: 'entrar', query: { volta: to.fullPath } }
  // Área restrita: o caseiro vai para o operacional.
  if (to.meta.perfis && !to.meta.perfis.includes(usuario.value.perfil)) return inicioDoPerfil(usuario.value.perfil)
  return true
})

export default router
