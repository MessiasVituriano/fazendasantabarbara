<script setup>
import { ref } from 'vue'
import { CircleCheck, LogIn } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import TelaPublica from '../components/layout/TelaPublica.vue'

/** Criação da nova senha pelo link (do e-mail ou mandado pelo gestor). O link vale uma vez. */
const props = defineProps({ token: { type: String, required: true } })

const senha = ref('')
const confirmacao = ref('')
const enviando = ref(false)
const erros = ref([])
const pronto = ref(null)

async function salvar() {
  enviando.value = true
  erros.value = []
  try {
    const { data } = await http.post('/senha/redefinir', { token: props.token, senha: senha.value, senha_confirmation: confirmacao.value })
    pronto.value = data.data.usuario
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
        <CardTitle>Criar nova senha</CardTitle>
        <CardDescription v-if="!pronto">Escolha uma senha com pelo menos 6 caracteres. Os aparelhos conectados com a senha antiga vão pedir login de novo.</CardDescription>
      </CardHeader>
      <CardContent>
        <div v-if="pronto" class="grid gap-4">
          <p class="flex gap-2 rounded-md bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
            <CircleCheck class="mt-0.5 size-4 shrink-0" /> Senha criada. Entre com o usuário "{{ pronto }}" e a nova senha.
          </p>
          <Button as="a" href="/entrar"><LogIn /> Entrar</Button>
        </div>
        <form v-else class="grid gap-4" @submit.prevent="salvar">
          <AlertaErros :mensagens="erros" />
          <div class="grid gap-1.5">
            <Label for="senha">Nova senha</Label>
            <Input id="senha" v-model="senha" type="password" autocomplete="new-password" required />
          </div>
          <div class="grid gap-1.5">
            <Label for="confirmacao">Repita a nova senha</Label>
            <Input id="confirmacao" v-model="confirmacao" type="password" autocomplete="new-password" required />
          </div>
          <Button type="submit" :disabled="enviando">{{ enviando ? 'Salvando…' : 'Salvar nova senha' }}</Button>
        </form>
      </CardContent>
    </Card>
  </TelaPublica>
</template>
