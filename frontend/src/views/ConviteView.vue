<script setup>
import { reactive, ref } from 'vue'
import { LogIn, UserPlus } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import TelaPublica from '../components/layout/TelaPublica.vue'
import { useSessao } from '../composables/useSessao'
import { entrarNaFazenda } from '../composables/useTrocaFazenda'

/**
 * Página do link de convite: quem recebeu cria o próprio acesso (usuário e senha) ou, se já trabalha em outra
 * fazenda pelo sistema, usa o acesso que tem. Depois de aceitar, já entra na fazenda do convite.
 */
const props = defineProps({ token: { type: String, required: true } })
const { iniciar } = useSessao()

const convite = ref(null)
const erroCarga = ref([])
const modo = ref('novo')
const form = reactive({ nome: '', usuario: '', senha: '', confirmacao: '' })
const enviando = ref(false)
const erros = ref([])
const campos = ref({})

http.get(`/convites/${props.token}`)
  .then(({ data }) => { convite.value = data.data; form.nome = data.data.nome })
  .catch((e) => { erroCarga.value = e.mensagens })

async function aceitar() {
  enviando.value = true
  erros.value = []
  campos.value = {}
  const dados = modo.value === 'novo'
    ? { modo: 'novo', nome: form.nome, usuario: form.usuario, senha: form.senha, senha_confirmation: form.confirmacao }
    : { modo: 'existente', usuario: form.usuario, senha: form.senha }
  try {
    const { data } = await http.post(`/convites/${props.token}/aceitar`, dados)
    const s = data.data
    iniciar(s.token, s.usuario, s.fazendas, { admin: s.admin, fazendaId: s.fazenda_id })
    entrarNaFazenda(s.fazenda_id)
  } catch (e) {
    erros.value = Object.keys(e.campos).length ? [] : e.mensagens
    campos.value = e.campos
    form.senha = ''
    form.confirmacao = ''
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <TelaPublica>
    <AlertaErros :mensagens="erroCarga" />
    <Card v-if="erroCarga.length">
      <CardContent class="grid gap-3 pt-6">
        <Button as="a" href="/entrar" variant="outline"><LogIn /> Ir para o login</Button>
      </CardContent>
    </Card>

    <Card v-else-if="!convite"><CardContent class="space-y-3 pt-6"><Skeleton class="h-6 w-2/3" /><Skeleton class="h-24 w-full" /></CardContent></Card>

    <Card v-else>
      <CardHeader>
        <CardTitle>Olá, {{ convite.nome }}!</CardTitle>
        <CardDescription>Você foi convidado(a) para acessar a <b class="text-foreground">{{ convite.fazenda }}</b> como {{ convite.perfil_label }}.</CardDescription>
      </CardHeader>
      <CardContent class="grid gap-4">
        <div class="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1" role="tablist">
          <button
            v-for="m in [{ v: 'novo', l: 'Criar meu acesso' }, { v: 'existente', l: 'Já tenho acesso' }]" :key="m.v"
            type="button" role="tab" :aria-selected="modo === m.v"
            class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors" :class="modo === m.v ? 'bg-background shadow-sm' : 'text-muted-foreground'"
            @click="modo = m.v; erros = []; campos = {}"
          >{{ m.l }}</button>
        </div>

        <form class="grid gap-4" @submit.prevent="aceitar">
          <AlertaErros :mensagens="erros" />
          <div v-if="modo === 'novo'" class="grid gap-1.5">
            <Label for="c-nome">Seu nome</Label>
            <Input id="c-nome" v-model.trim="form.nome" required />
          </div>
          <div class="grid gap-1.5">
            <Label for="c-usuario">{{ modo === 'novo' ? 'Escolha um usuário (login)' : 'Seu usuário' }}</Label>
            <Input id="c-usuario" v-model.trim="form.usuario" autocomplete="username" autocapitalize="none" :placeholder="modo === 'novo' ? 'ex.: ana.souza' : ''" required :aria-invalid="!!campos.usuario" />
            <p v-if="campos.usuario" class="text-xs text-destructive">{{ campos.usuario }}</p>
          </div>
          <div class="grid gap-1.5">
            <Label for="c-senha">{{ modo === 'novo' ? 'Crie uma senha' : 'Sua senha' }}</Label>
            <Input id="c-senha" v-model="form.senha" type="password" :autocomplete="modo === 'novo' ? 'new-password' : 'current-password'" required :aria-invalid="!!campos.senha" />
            <p v-if="campos.senha" class="text-xs text-destructive">{{ campos.senha }}</p>
          </div>
          <div v-if="modo === 'novo'" class="grid gap-1.5">
            <Label for="c-confirmacao">Repita a senha</Label>
            <Input id="c-confirmacao" v-model="form.confirmacao" type="password" autocomplete="new-password" required />
          </div>
          <Button type="submit" :disabled="enviando">
            <component :is="modo === 'novo' ? UserPlus : LogIn" /> {{ enviando ? 'Entrando…' : modo === 'novo' ? 'Criar acesso e entrar' : 'Aceitar e entrar' }}
          </Button>
        </form>
      </CardContent>
    </Card>
  </TelaPublica>
</template>
