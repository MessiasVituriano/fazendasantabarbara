<script setup>
import { computed, reactive, onMounted, watch } from 'vue'
import { KeyRound, Lock, Save, ShieldCheck, Tractor, UserPen } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { useEnvio } from '../composables/useEnvio'
import { useOpcoes } from '../composables/useOpcoes'
import { useSessao } from '../composables/useSessao'
import { formatarTelefone, mascaraTelefone } from '../formatos'

/** Meu perfil: dados do usuário logado. Só o gestor edita os próprios dados e a senha. */
const { usuario, ehGestor, atualizarUsuario } = useSessao()
const { opcoes } = useOpcoes()
const ICONE = { GESTOR: ShieldCheck, CASEIRO: Tractor }

onMounted(async () => {
  try { atualizarUsuario((await http.get('/eu')).data.data) } catch { /* mantém os dados da sessão */ }
})

const iniciais = computed(() => (usuario.value?.nome ?? '?').split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase())
const descricaoPerfil = computed(() => opcoes.value?.perfil?.find((p) => p.value === usuario.value?.perfil)?.descricao)
const dados = computed(() => usuario.value && [
  ['Nome', usuario.value.nome],
  ['Usuário (login)', usuario.value.usuario],
  ['E-mail', usuario.value.email || '—'],
  ['Telefone', formatarTelefone(usuario.value.telefone) || '—'],
])

// Edição dos próprios dados (gestor)
const cadastro = reactive({ nome: '', usuario: '', email: '', telefone: '' })
const doUsuario = (u) => ({ nome: u?.nome ?? '', usuario: u?.usuario ?? '', email: u?.email ?? '', telefone: formatarTelefone(u?.telefone) })
watch(usuario, (u) => Object.assign(cadastro, doUsuario(u)), { immediate: true })
const alterado = computed(() => JSON.stringify(doUsuario(usuario.value)) !== JSON.stringify({ ...cadastro }))
const envioDados = useEnvio()
async function salvarDados() {
  const loginAntes = usuario.value.usuario
  try {
    const { data } = await envioDados.enviar(() => http.put('/eu', {
      nome: cadastro.nome, usuario: cadastro.usuario, email: cadastro.email || null, telefone: cadastro.telefone || null,
    }))
    atualizarUsuario(data.data)
    toast.success('Dados atualizados', {
      description: data.data.usuario !== loginAntes ? `Use "${data.data.usuario}" no próximo login.` : undefined,
    })
  } catch { /* exibido no formulário */ }
}

const form = reactive({ senha_atual: '', nova_senha: '', nova_senha_confirmation: '' })
const { enviando, mensagens, campos, enviar } = useEnvio()
async function salvarSenha() {
  try {
    await enviar(() => http.put('/eu/senha', form))
    toast.success('Senha alterada', { description: 'As outras sessões abertas foram encerradas.' })
    Object.assign(form, { senha_atual: '', nova_senha: '', nova_senha_confirmation: '' })
  } catch { /* exibido no formulário */ }
}
</script>

<template>
  <CabecalhoPagina titulo="Meu perfil" />

  <main v-if="usuario" class="mx-auto max-w-3xl space-y-4 px-4 py-6 md:px-6">
    <Card>
      <CardContent class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
        <span class="flex size-16 shrink-0 items-center justify-center rounded-full bg-primary/15 text-xl font-semibold text-primary">{{ iniciais }}</span>
        <div class="min-w-0 space-y-1">
          <p class="truncate text-xl font-semibold">{{ usuario.nome }}</p>
          <Badge :variant="ehGestor ? 'default' : 'secondary'"><component :is="ICONE[usuario.perfil]" /> {{ usuario.perfil_label }}</Badge>
          <p v-if="descricaoPerfil" class="text-sm text-muted-foreground">{{ descricaoPerfil }}</p>
        </div>
      </CardContent>
    </Card>

    <!-- Gestor: dados editáveis -->
    <Card v-if="ehGestor">
      <CardHeader>
        <CardTitle class="flex items-center gap-2"><UserPen class="size-4" /> Dados pessoais</CardTitle>
        <CardDescription>Nome, login, e-mail e telefone.</CardDescription>
      </CardHeader>
      <CardContent>
        <form id="form-dados" class="grid gap-4 sm:grid-cols-2" @submit.prevent="salvarDados">
          <AlertaErros class="sm:col-span-2" :mensagens="Object.keys(envioDados.campos.value).length ? [] : envioDados.mensagens.value" />
          <div class="grid gap-1.5">
            <Label for="p-nome">Nome</Label>
            <Input id="p-nome" v-model.trim="cadastro.nome" autocomplete="name" required :aria-invalid="!!envioDados.campos.value.nome" />
            <p v-if="envioDados.campos.value.nome" class="text-xs text-destructive">{{ envioDados.campos.value.nome }}</p>
          </div>
          <div class="grid gap-1.5">
            <Label for="p-usuario">Usuário (login)</Label>
            <Input id="p-usuario" v-model.trim="cadastro.usuario" autocomplete="username" autocapitalize="none" required :aria-invalid="!!envioDados.campos.value.usuario" />
            <p v-if="envioDados.campos.value.usuario" class="text-xs text-destructive">{{ envioDados.campos.value.usuario }}</p>
          </div>
          <div class="grid gap-1.5">
            <Label for="p-email">E-mail</Label>
            <Input id="p-email" v-model.trim="cadastro.email" type="email" autocomplete="email" placeholder="opcional" :aria-invalid="!!envioDados.campos.value.email" />
            <p v-if="envioDados.campos.value.email" class="text-xs text-destructive">{{ envioDados.campos.value.email }}</p>
          </div>
          <div class="grid gap-1.5">
            <Label for="p-telefone">Telefone</Label>
            <Input id="p-telefone" :model-value="cadastro.telefone" type="tel" inputmode="tel" autocomplete="tel" placeholder="(11) 98765-4321"
                   :aria-invalid="!!envioDados.campos.value.telefone" @update:model-value="(v) => (cadastro.telefone = mascaraTelefone(v))" />
            <p v-if="envioDados.campos.value.telefone" class="text-xs text-destructive">{{ envioDados.campos.value.telefone }}</p>
          </div>
        </form>
      </CardContent>
      <CardFooter class="justify-end gap-2">
        <Button v-if="alterado" variant="ghost" @click="Object.assign(cadastro, doUsuario(usuario))">Descartar</Button>
        <Button type="submit" form="form-dados" :disabled="!alterado || envioDados.enviando.value"><Save /> {{ envioDados.enviando.value ? 'Salvando…' : 'Salvar alterações' }}</Button>
      </CardFooter>
    </Card>

    <!-- Caseiro: somente consulta -->
    <Card v-else>
      <CardHeader><CardTitle>Dados pessoais</CardTitle></CardHeader>
      <CardContent>
        <dl class="divide-y text-sm">
          <div v-for="[rotulo, valor] in dados" :key="rotulo" class="flex flex-col gap-0.5 py-2.5 sm:flex-row sm:justify-between">
            <dt class="text-muted-foreground">{{ rotulo }}</dt><dd class="font-medium break-all">{{ valor }}</dd>
          </div>
        </dl>
      </CardContent>
    </Card>

    <Card v-if="ehGestor">
      <CardHeader>
        <CardTitle class="flex items-center gap-2"><KeyRound class="size-4" /> Alterar senha</CardTitle>
        <CardDescription>Mínimo de 6 caracteres. As outras sessões abertas serão encerradas.</CardDescription>
      </CardHeader>
      <CardContent>
        <form id="form-senha" class="grid gap-4 sm:grid-cols-3" @submit.prevent="salvarSenha">
          <AlertaErros class="sm:col-span-3" :mensagens="Object.keys(campos).length ? [] : mensagens" />
          <div class="grid gap-1.5">
            <Label for="senha-atual">Senha atual</Label>
            <Input id="senha-atual" v-model="form.senha_atual" type="password" autocomplete="current-password" required :aria-invalid="!!campos.senha_atual" />
            <p v-if="campos.senha_atual" class="text-xs text-destructive">{{ campos.senha_atual }}</p>
          </div>
          <div class="grid gap-1.5">
            <Label for="nova-senha">Nova senha</Label>
            <Input id="nova-senha" v-model="form.nova_senha" type="password" autocomplete="new-password" required :aria-invalid="!!campos.nova_senha" />
            <p v-if="campos.nova_senha" class="text-xs text-destructive">{{ campos.nova_senha }}</p>
          </div>
          <div class="grid gap-1.5">
            <Label for="confirma-senha">Confirmar nova senha</Label>
            <Input id="confirma-senha" v-model="form.nova_senha_confirmation" type="password" autocomplete="new-password" required />
          </div>
        </form>
      </CardContent>
      <CardFooter class="justify-end">
        <Button type="submit" form="form-senha" :disabled="enviando">{{ enviando ? 'Salvando…' : 'Salvar nova senha' }}</Button>
      </CardFooter>
    </Card>

    <Alert v-else>
      <Lock />
      <AlertTitle>Senha gerenciada pelo gestor</AlertTitle>
      <AlertDescription>Para trocar sua senha ou alterar seus dados, fale com o gestor da fazenda.</AlertDescription>
    </Alert>
  </main>
</template>
