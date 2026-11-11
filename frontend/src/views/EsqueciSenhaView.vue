<script setup>
import { ref } from 'vue'
import { ArrowLeft, Mail } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import TelaPublica from '../components/layout/TelaPublica.vue'

/** Pedido de nova senha: o link vai para o e-mail cadastrado; sem e-mail, o gestor gera o link. */
const usuario = ref('')
const enviando = ref(false)
const erros = ref([])
const resposta = ref(null)

async function pedir() {
  enviando.value = true
  erros.value = []
  try {
    resposta.value = (await http.post('/senha/esqueci', { usuario: usuario.value })).data.data.mensagem
  } catch (e) {
    erros.value = e.mensagens
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <TelaPublica>
    <Card>
      <CardHeader>
        <CardTitle>Esqueci minha senha</CardTitle>
        <CardDescription>Informe o seu usuário ou e-mail. Se houver e-mail cadastrado, mandamos um link para criar uma nova senha.</CardDescription>
      </CardHeader>
      <CardContent>
        <div v-if="resposta" class="grid gap-4">
          <p class="flex gap-2 rounded-md bg-muted px-3 py-2.5 text-sm"><Mail class="mt-0.5 size-4 shrink-0" /> {{ resposta }}</p>
          <Button as="a" href="/entrar" variant="outline"><ArrowLeft /> Voltar para o login</Button>
        </div>
        <form v-else class="grid gap-4" @submit.prevent="pedir">
          <AlertaErros :mensagens="erros" />
          <div class="grid gap-1.5">
            <Label for="usuario">Usuário ou e-mail</Label>
            <Input id="usuario" v-model="usuario" autocomplete="username" autocapitalize="none" autofocus required />
          </div>
          <Button type="submit" :disabled="enviando">{{ enviando ? 'Enviando…' : 'Enviar link' }}</Button>
          <Button as="a" href="/entrar" variant="ghost" class="text-muted-foreground"><ArrowLeft /> Voltar para o login</Button>
        </form>
      </CardContent>
    </Card>
  </TelaPublica>
</template>
