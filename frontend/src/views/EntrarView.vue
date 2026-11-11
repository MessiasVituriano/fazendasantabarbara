<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { LogIn, Wheat } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import { inicioDoPerfil, useSessao } from '../composables/useSessao'
import { useSistema } from '../composables/useSistema'

const router = useRouter()
const route = useRoute()
const { iniciar, fazendaId, usuario: logado, fazendas } = useSessao()
const { produto } = useSistema()

const usuario = ref('')
const senha = ref('')
const entrando = ref(false)
const erros = ref([])

async function entrar() {
  entrando.value = true
  erros.value = []
  try {
    const { data } = await http.post('/login', { usuario: usuario.value, senha: senha.value, dispositivo: 'web' })
    iniciar(data.data.token, data.data.usuario, data.data.fazendas, { admin: data.data.admin, fazendaId: data.data.fazenda_id })
    const volta = typeof route.query.volta === 'string' && route.query.volta.startsWith('/') ? route.query.volta : null
    // Administrador sem fazenda vai para a administração; com mais de uma fazenda, primeiro escolhe em qual vai trabalhar.
    if (data.data.admin && !fazendas.value.length) await router.replace({ name: 'admin' })
    else if (!fazendaId.value) await router.replace({ name: 'fazendas', query: volta ? { volta } : {} })
    else await router.replace(volta ?? inicioDoPerfil(logado.value.perfil))
  } catch (e) {
    erros.value = e.mensagens
    senha.value = ''
  } finally {
    entrando.value = false
  }
}
</script>

<template>
  <main class="flex min-h-screen w-full items-center justify-center bg-muted/40 p-4">
    <div class="w-full max-w-sm space-y-6">
      <div class="flex flex-col items-center gap-3 text-center">
        <span class="flex size-12 items-center justify-center rounded-xl bg-primary text-primary-foreground"><Wheat class="size-7" /></span>
        <div>
          <h1 class="text-xl font-semibold">{{ produto }}</h1>
          <p class="text-sm text-muted-foreground">Entre para acessar a sua fazenda</p>
        </div>
      </div>
      <Card>
        <CardHeader>
          <CardTitle>Entrar</CardTitle>
          <CardDescription>Use o usuário e a senha fornecidos pelo gestor da fazenda.</CardDescription>
        </CardHeader>
        <CardContent>
          <form class="grid gap-4" @submit.prevent="entrar">
            <AlertaErros :mensagens="erros" />
            <div class="grid gap-1.5">
              <Label for="usuario">Usuário</Label>
              <Input id="usuario" v-model="usuario" autocomplete="username" autocapitalize="none" autofocus required />
            </div>
            <div class="grid gap-1.5">
              <Label for="senha">Senha</Label>
              <Input id="senha" v-model="senha" type="password" autocomplete="current-password" required />
            </div>
            <Button type="submit" class="w-full" :disabled="entrando"><LogIn /> {{ entrando ? 'Entrando…' : 'Entrar' }}</Button>
            <RouterLink to="/esqueci-senha" class="text-center text-sm text-muted-foreground underline-offset-4 hover:underline">Esqueci minha senha</RouterLink>
          </form>
        </CardContent>
      </Card>
    </div>
  </main>
</template>
