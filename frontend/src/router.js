import { createRouter, createWebHistory } from 'vue-router'
import { inicioDoPerfil, useSessao } from './composables/useSessao'

const SO_GESTOR = { perfis: ['GESTOR'] }

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/entrar', name: 'entrar', component: () => import('./views/EntrarView.vue'), meta: { publica: true } },
    { path: '/', name: 'painel', component: () => import('./views/PainelView.vue'), meta: SO_GESTOR },
    { path: '/animais', name: 'animais', component: () => import('./views/AnimaisView.vue') },
    { path: '/animais/:id', name: 'animal', component: () => import('./views/AnimalView.vue'), props: true },
    { path: '/ordenha', name: 'ordenha', component: () => import('./views/OrdenhaView.vue') },
    { path: '/pesagens', name: 'pesagens', component: () => import('./views/PesagensView.vue') },
    { path: '/abate', name: 'abate', component: () => import('./views/AbateView.vue'), meta: SO_GESTOR },
    { path: '/piquetes', name: 'piquetes', component: () => import('./views/PiquetesView.vue') },
    { path: '/perfil', name: 'perfil', component: () => import('./views/PerfilView.vue') },
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
