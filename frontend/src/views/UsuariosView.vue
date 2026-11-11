<script setup>
import { computed, reactive, ref } from 'vue'
import { KeyRound, Pencil, Plus, Send, ShieldCheck, Tractor, X } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Toggle } from '@/components/ui/toggle'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import LinkCompartilhavel from '../components/LinkCompartilhavel.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { useEnvio } from '../composables/useEnvio'
import { useOpcoes } from '../composables/useOpcoes'
import { useSessao } from '../composables/useSessao'
import { formatarTelefone, mascaraTelefone } from '../formatos'

/**
 * Usuários da fazenda (gestor): perfis Gestor e Caseiro; desativar em vez de excluir. Convite por link (a pessoa cria
 * o próprio acesso) e link de nova senha, os dois para mandar pelo WhatsApp.
 */
const { opcoes } = useOpcoes()
const { usuario: eu, atualizarUsuario } = useSessao()
const usuarios = ref([])
const erroCarga = ref([])
const ICONE = { GESTOR: ShieldCheck, CASEIRO: Tractor }

const convites = ref([])
async function carregar() {
  try {
    const [u, c] = await Promise.all([http.get('/usuarios'), http.get('/usuarios/convites')])
    usuarios.value = u.data.data
    convites.value = c.data.data
  } catch (e) { erroCarga.value = e.mensagens }
}
carregar()

// Convite por link
const convite = reactive({ aberto: false, nome: '', telefone: '', email: '', perfil: 'CASEIRO', gerado: null, erros: [], campos: {}, enviando: false })
function abrirConvite() {
  Object.assign(convite, { aberto: true, nome: '', telefone: '', email: '', perfil: 'CASEIRO', gerado: null, erros: [], campos: {}, enviando: false })
}
async function convidar() {
  Object.assign(convite, { erros: [], campos: {}, enviando: true })
  try {
    const { data } = await http.post('/usuarios/convites', {
      nome: convite.nome, perfil: convite.perfil, telefone: convite.telefone || null, email: convite.email || null,
    })
    convite.gerado = data.data
    carregar()
  } catch (e) {
    convite.campos = e.campos
    convite.erros = Object.keys(e.campos).length ? [] : e.mensagens
  } finally {
    convite.enviando = false
  }
}
async function cancelarConvite(c) {
  if (!confirm(`Cancelar o convite de ${c.nome}? O link deixa de funcionar.`)) return
  try { await http.post(`/usuarios/convites/${c.id}/cancelamento`); carregar() } catch (e) { toast.error(e.mensagens[0]) }
}
const validoAte = (iso) => new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })

// Link de nova senha (para quem esqueceu e não tem e-mail)
const linkSenha = ref(null)
const gerandoLink = ref(false)
async function gerarLinkSenha() {
  gerandoLink.value = true
  try { linkSenha.value = (await http.post(`/usuarios/${editando.value.id}/link-senha`)).data.data } catch (e) { mensagens.value = e.mensagens }
  gerandoLink.value = false
}

const ativos = computed(() => usuarios.value.filter((u) => u.ativo).length)
const quando = (iso) => {
  if (!iso) return 'Nunca entrou'
  return new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })
}

// Criar / editar
const aberto = ref(false)
const editando = ref(null)
const form = reactive({ nome: '', usuario: '', email: '', telefone: '', perfil: 'CASEIRO', ativo: true, senha: '' })
const { enviando, mensagens, campos, enviar } = useEnvio()

function abrir(u = null) {
  editando.value = u
  linkSenha.value = null
  mensagens.value = []
  campos.value = {}
  Object.assign(form, u
    ? { nome: u.nome, usuario: u.usuario, email: u.email ?? '', telefone: formatarTelefone(u.telefone), perfil: u.perfil, ativo: u.ativo, senha: '' }
    : { nome: '', usuario: '', email: '', telefone: '', perfil: 'CASEIRO', ativo: true, senha: '' })
  aberto.value = true
}

async function salvar() {
  const dados = { ...form, email: form.email || null, telefone: form.telefone || null, senha: form.senha || null }
  try {
    const { data } = await enviar(() => editando.value
      ? http.patch(`/usuarios/${editando.value.id}`, dados)
      : http.post('/usuarios', dados))
    toast.success(editando.value ? 'Usuário atualizado' : 'Usuário criado', {
      description: editando.value ? data.data.nome : `${data.data.nome} já pode entrar com o usuário "${data.data.usuario}".`,
    })
    if (data.data.id === eu.value?.id) atualizarUsuario(data.data)
    aberto.value = false
    carregar()
  } catch { /* exibido no formulário */ }
}
</script>

<template>
  <CabecalhoPagina titulo="Usuários" :contador="`${ativos} ativo(s)`">
    <template #acoes>
      <Button variant="outline" @click="abrir()"><Plus /> <span class="hidden sm:inline">Novo usuário</span></Button>
      <Button @click="abrirConvite"><Send /> Convidar</Button>
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erroCarga" />

    <div class="grid gap-4 sm:grid-cols-2">
      <div v-for="p in opcoes?.perfil" :key="p.value" class="flex gap-3 rounded-xl border bg-card p-4">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted"><component :is="ICONE[p.value]" class="size-5" /></span>
        <div>
          <p class="font-medium">{{ p.label }}</p>
          <p class="text-sm text-muted-foreground">{{ p.descricao }}</p>
        </div>
      </div>
    </div>

    <div v-if="convites.length" class="overflow-hidden rounded-xl border bg-card">
      <p class="border-b px-4 py-2.5 text-sm font-medium">Convites aguardando</p>
      <ul class="divide-y">
        <li v-for="c in convites" :key="c.id" class="flex items-center gap-3 px-4 py-2.5">
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium">{{ c.nome }} <Badge variant="secondary" class="ml-1">{{ c.perfil_label }}</Badge></p>
            <p class="text-xs text-muted-foreground">{{ [formatarTelefone(c.telefone), c.email].filter(Boolean).join(' · ') || 'Sem contato' }} · vale até {{ validoAte(c.expira_em) }}<template v-if="c.convidado_por"> · por {{ c.convidado_por }}</template></p>
          </div>
          <Button variant="ghost" size="icon" :aria-label="`Cancelar convite de ${c.nome}`" @click="cancelarConvite(c)"><X /></Button>
        </li>
      </ul>
    </div>

    <div class="overflow-hidden rounded-xl border bg-card">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Nome</TableHead><TableHead>Usuário</TableHead><TableHead>Perfil</TableHead>
            <TableHead>Situação</TableHead><TableHead>Último acesso</TableHead><TableHead />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="u in usuarios" :key="u.id" :class="{ 'opacity-60': !u.ativo }">
            <TableCell>
              <p class="font-medium">{{ u.nome }} <span v-if="u.id === eu?.id" class="text-xs font-normal text-muted-foreground">(você)</span></p>
              <p v-if="u.email || u.telefone" class="text-xs text-muted-foreground">{{ [u.email, formatarTelefone(u.telefone)].filter(Boolean).join(' · ') }}</p>
            </TableCell>
            <TableCell class="font-mono text-sm">{{ u.usuario }}</TableCell>
            <TableCell>
              <Badge :variant="u.perfil === 'GESTOR' ? 'default' : 'secondary'"><component :is="ICONE[u.perfil]" /> {{ u.perfil_label }}</Badge>
            </TableCell>
            <TableCell>
              <Badge v-if="u.ativo" variant="outline" class="text-emerald-700 dark:text-emerald-400">Ativo</Badge>
              <Badge v-else variant="outline">Desativado</Badge>
            </TableCell>
            <TableCell class="text-sm text-muted-foreground">{{ quando(u.ultimo_acesso) }}</TableCell>
            <TableCell class="text-right">
              <Button variant="ghost" size="icon" :aria-label="`Editar ${u.nome}`" @click="abrir(u)"><Pencil /></Button>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>
  </main>

  <Dialog v-model:open="aberto">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ editando ? 'Editar usuário' : 'Novo usuário' }}</DialogTitle>
        <DialogDescription>{{ editando ? 'Alterar perfil, desativar ou redefinir a senha encerra as sessões abertas deste usuário.' : 'Informe os dados de acesso e o perfil.' }}</DialogDescription>
      </DialogHeader>
      <form id="form-usuario" class="grid gap-4" @submit.prevent="salvar">
        <AlertaErros :mensagens="Object.keys(campos).length ? [] : mensagens" />
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="grid gap-1.5">
            <Label for="u-nome">Nome</Label>
            <Input id="u-nome" v-model.trim="form.nome" required :aria-invalid="!!campos.nome" />
            <p v-if="campos.nome" class="text-xs text-destructive">{{ campos.nome }}</p>
          </div>
          <div class="grid gap-1.5">
            <Label for="u-usuario">Usuário (login)</Label>
            <Input id="u-usuario" v-model.trim="form.usuario" autocapitalize="none" placeholder="ex.: joao" required :aria-invalid="!!campos.usuario" />
            <p v-if="campos.usuario" class="text-xs text-destructive">{{ campos.usuario }}</p>
          </div>
          <div class="grid gap-1.5">
            <Label for="u-email">E-mail (opcional)</Label>
            <Input id="u-email" v-model.trim="form.email" type="email" :aria-invalid="!!campos.email" />
            <p v-if="campos.email" class="text-xs text-destructive">{{ campos.email }}</p>
          </div>
          <div class="grid gap-1.5">
            <Label for="u-telefone">Telefone (opcional)</Label>
            <Input id="u-telefone" :model-value="form.telefone" type="tel" inputmode="tel" placeholder="(11) 98765-4321" :aria-invalid="!!campos.telefone"
                   @update:model-value="(v) => (form.telefone = mascaraTelefone(v))" />
            <p v-if="campos.telefone" class="text-xs text-destructive">{{ campos.telefone }}</p>
          </div>
        </div>

        <fieldset class="grid gap-2">
          <legend class="mb-1.5 text-sm font-medium">Perfil</legend>
          <label
            v-for="p in opcoes?.perfil" :key="p.value"
            class="flex cursor-pointer gap-3 rounded-lg border p-3 transition-colors"
            :class="form.perfil === p.value ? 'border-primary bg-primary/5' : 'hover:bg-accent'"
          >
            <input v-model="form.perfil" type="radio" name="perfil" :value="p.value" class="mt-1 size-4 accent-primary" />
            <span>
              <span class="flex items-center gap-1.5 font-medium"><component :is="ICONE[p.value]" class="size-4" /> {{ p.label }}</span>
              <span class="text-sm text-muted-foreground">{{ p.descricao }}</span>
            </span>
          </label>
          <p v-if="campos.perfil" class="text-xs text-destructive">{{ campos.perfil }}</p>
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="grid gap-1.5">
            <Label for="u-senha">{{ editando ? 'Nova senha (opcional)' : 'Senha' }}</Label>
            <Input id="u-senha" v-model="form.senha" type="password" autocomplete="new-password" :required="!editando" :placeholder="editando ? 'Deixe em branco para manter' : 'Mínimo 6 caracteres'" :aria-invalid="!!campos.senha" />
            <p v-if="campos.senha" class="text-xs text-destructive">{{ campos.senha }}</p>
          </div>
          <div v-if="editando && editando.id !== eu?.id" class="grid gap-1.5 sm:col-span-2">
            <Label>Esqueceu a senha?</Label>
            <LinkCompartilhavel v-if="linkSenha" :link="linkSenha.link" :whatsapp="linkSenha.whatsapp" aviso="O link vale por 24 horas e só pode ser usado uma vez." />
            <Button v-else type="button" variant="outline" class="justify-start" :disabled="gerandoLink" @click="gerarLinkSenha">
              <KeyRound /> Gerar link para {{ editando.nome.split(' ')[0] }} criar uma nova senha
            </Button>
          </div>
          <div v-if="editando" class="grid gap-1.5">
            <Label>Acesso</Label>
            <Toggle v-model="form.ativo" variant="outline" class="justify-start data-[state=on]:bg-emerald-100 data-[state=on]:text-emerald-800 dark:data-[state=on]:bg-emerald-950 dark:data-[state=on]:text-emerald-300">
              {{ form.ativo ? 'Ativo (pode entrar)' : 'Desativado' }}
            </Toggle>
          </div>
        </div>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="aberto = false">Cancelar</Button>
        <Button type="submit" form="form-usuario" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <Dialog v-model:open="convite.aberto">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ convite.gerado ? `Convite para ${convite.gerado.nome}` : 'Convidar para a fazenda' }}</DialogTitle>
        <DialogDescription>
          <template v-if="convite.gerado">Mande o link. A pessoa cria o próprio usuário e senha (ou usa o acesso que já tem em outra fazenda). Vale por 7 dias.</template>
          <template v-else>Em vez de criar usuário e senha por ela, mande um link: a própria pessoa cria o acesso.</template>
        </DialogDescription>
      </DialogHeader>
      <LinkCompartilhavel v-if="convite.gerado" :link="convite.gerado.link" :whatsapp="convite.gerado.whatsapp"
                          :aviso="convite.gerado.email ? `Também enviado para ${convite.gerado.email}.` : null" />
      <form v-else id="form-convite" class="grid gap-4" @submit.prevent="convidar">
        <AlertaErros :mensagens="convite.erros" />
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="grid gap-1.5 sm:col-span-2">
            <Label for="cv-nome">Nome</Label>
            <Input id="cv-nome" v-model.trim="convite.nome" required :aria-invalid="!!convite.campos.nome" />
          </div>
          <div class="grid gap-1.5">
            <Label for="cv-telefone">WhatsApp</Label>
            <Input id="cv-telefone" :model-value="convite.telefone" type="tel" inputmode="tel" placeholder="(11) 98765-4321" :aria-invalid="!!convite.campos.telefone"
                   @update:model-value="(v) => (convite.telefone = mascaraTelefone(v))" />
            <p v-if="convite.campos.telefone" class="text-xs text-destructive">{{ convite.campos.telefone }}</p>
          </div>
          <div class="grid gap-1.5">
            <Label for="cv-email">E-mail (opcional)</Label>
            <Input id="cv-email" v-model.trim="convite.email" type="email" :aria-invalid="!!convite.campos.email" />
            <p v-if="convite.campos.email" class="text-xs text-destructive">{{ convite.campos.email }}</p>
          </div>
        </div>
        <fieldset class="grid gap-2">
          <legend class="mb-1.5 text-sm font-medium">Perfil</legend>
          <label v-for="p in opcoes?.perfil" :key="p.value" class="flex cursor-pointer gap-3 rounded-lg border p-3 transition-colors"
                 :class="convite.perfil === p.value ? 'border-primary bg-primary/5' : 'hover:bg-accent'">
            <input v-model="convite.perfil" type="radio" name="perfil-convite" :value="p.value" class="mt-1 size-4 accent-primary" />
            <span>
              <span class="flex items-center gap-1.5 font-medium"><component :is="ICONE[p.value]" class="size-4" /> {{ p.label }}</span>
              <span class="text-sm text-muted-foreground">{{ p.descricao }}</span>
            </span>
          </label>
        </fieldset>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="convite.aberto = false">{{ convite.gerado ? 'Fechar' : 'Cancelar' }}</Button>
        <Button v-if="!convite.gerado" type="submit" form="form-convite" :disabled="convite.enviando"><Send /> Gerar convite</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
