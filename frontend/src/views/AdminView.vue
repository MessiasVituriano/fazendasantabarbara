<script setup>
import { computed, reactive, ref } from 'vue'
import { Building2, Download, Headset, LogOut, Plus, Power, UserPlus, Wheat } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import LinkCompartilhavel from '../components/LinkCompartilhavel.vue'
import { useSessao } from '../composables/useSessao'
import { useSistema } from '../composables/useSistema'
import { entrarNaFazenda } from '../composables/useTrocaFazenda'
import { baixarArquivo } from '../api/download'
import { mascaraTelefone } from '../formatos'
import { limparLeituras } from '../offline/cache'

/**
 * Administração da plataforma: clientes (produtores) com as fazendas, convite do responsável, suspensão,
 * suporte dentro de uma fazenda (com motivo e prazo) e exportação dos dados (LGPD).
 */
const { produto } = useSistema()
const { fazendas, atualizarFazendas, encerrar } = useSessao()
const clientes = ref(null)
const erroCarga = ref([])
const busca = ref('')

async function carregar() {
  try { clientes.value = (await http.get('/admin/clientes')).data.data } catch (e) { erroCarga.value = e.mensagens }
}
carregar()

const filtrados = computed(() => {
  const termo = busca.value.trim().toLowerCase()
  if (!clientes.value || !termo) return clientes.value ?? []
  return clientes.value.filter((c) => [c.nome, c.documento, ...c.fazendas.map((f) => f.nome)].some((t) => t?.toLowerCase().includes(termo)))
})
const local = (f) => [f.municipio, f.uf].filter(Boolean).join(' / ')
const data = (iso) => new Date(iso).toLocaleDateString('pt-BR')

// Novo cliente (com a primeira fazenda e o convite do responsável)
const novoAberto = ref(false)
const novo = reactive({ nome: '', documento: '', fazenda: { nome: '', municipio: '', uf: '' }, dono: { nome: '', email: '', telefone: '' } })
const enviando = ref(false)
const erros = ref([])
const campos = ref({})
const conviteGerado = ref(null)

function abrirNovo() {
  Object.assign(novo, { nome: '', documento: '', fazenda: { nome: '', municipio: '', uf: '' }, dono: { nome: '', email: '', telefone: '' } })
  erros.value = []
  campos.value = {}
  conviteGerado.value = null
  novoAberto.value = true
}

async function criarCliente() {
  enviando.value = true
  erros.value = []
  campos.value = {}
  try {
    const vazioNulo = (o) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v || null]))
    const { data: r } = await http.post('/admin/clientes', {
      nome: novo.nome, documento: novo.documento || null, fazenda: vazioNulo(novo.fazenda), dono: vazioNulo(novo.dono),
    })
    conviteGerado.value = { ...r.data.convite, nome: novo.dono.nome, email: novo.dono.email }
    toast.success('Cliente cadastrado', { description: `${r.data.cliente.nome} · ${r.data.fazenda.nome}` })
    carregar()
  } catch (e) {
    campos.value = e.campos
    erros.value = Object.keys(e.campos).length ? [] : e.mensagens
  } finally {
    enviando.value = false
  }
}

// Novo convite numa fazenda (ex.: o link do responsável expirou)
const convite = reactive({ aberto: false, fazenda: null, nome: '', email: '', telefone: '', dono: true, gerado: null, erros: [] })
function abrirConvite(fazenda) {
  Object.assign(convite, { aberto: true, fazenda, nome: '', email: '', telefone: '', dono: true, gerado: null, erros: [] })
}
async function convidar() {
  convite.erros = []
  try {
    const { data: r } = await http.post(`/admin/fazendas/${convite.fazenda.id}/convites`, {
      nome: convite.nome, perfil: 'GESTOR', email: convite.email || null, telefone: convite.telefone || null, dono: convite.dono,
    })
    convite.gerado = r.data
    carregar()
  } catch (e) { convite.erros = e.mensagens }
}

// Suspender / reativar
async function alternarSituacao(cliente) {
  const suspender = cliente.ativo
  if (suspender && !confirm(`Suspender ${cliente.nome}? Ninguém do cliente consegue entrar até reativar. Os dados ficam guardados.`)) return
  try {
    await http.patch(`/admin/clientes/${cliente.id}`, { ativo: !suspender })
    toast.success(suspender ? 'Cliente suspenso' : 'Cliente reativado', { description: cliente.nome })
    carregar()
  } catch (e) { toast.error(e.mensagens[0]) }
}

// Suporte: entra na fazenda como gestor por algumas horas, com o motivo registrado
const suporte = reactive({ aberto: false, fazenda: null, motivo: '', erros: [] })
function abrirSuporte(fazenda) { Object.assign(suporte, { aberto: true, fazenda, motivo: '', erros: [] }) }
async function entrarSuporte() {
  suporte.erros = []
  try {
    const { data: r } = await http.post(`/admin/fazendas/${suporte.fazenda.id}/suporte`, { motivo: suporte.motivo })
    atualizarFazendas([...fazendas.value.filter((f) => f.id !== r.data.id), r.data])
    entrarNaFazenda(r.data.id)
  } catch (e) { suporte.erros = e.mensagens }
}

async function exportar(cliente) {
  try {
    await baixarArquivo(`/admin/clientes/${cliente.id}/exportacao`, `dados-${cliente.id}.zip`)
  } catch (e) { toast.error('Não foi possível exportar', { description: e.mensagens?.[0] }) }
}

async function sair() {
  try { await http.post('/logout') } catch { /* encerra localmente mesmo sem rede */ }
  encerrar()
  limparLeituras()
  location.assign('/entrar')
}
</script>

<template>
  <header class="sticky top-0 z-10 flex h-14 items-center gap-3 border-b bg-background/95 px-4 backdrop-blur md:px-6">
    <span class="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground"><Wheat class="size-4" /></span>
    <div class="min-w-0 flex-1 leading-tight">
      <p class="truncate text-sm font-semibold">Administração</p>
      <p class="truncate text-xs text-muted-foreground">{{ produto }}</p>
    </div>
    <Button v-if="fazendas.some((f) => !f.suporte)" as="a" href="/fazendas" variant="ghost" size="sm"><Building2 /> <span class="hidden sm:inline">Minhas fazendas</span></Button>
    <Button variant="ghost" size="sm" @click="sair"><LogOut /> <span class="hidden sm:inline">Sair</span></Button>
  </header>

  <main class="mx-auto max-w-5xl space-y-4 px-4 py-6 md:px-6">
    <div class="flex flex-wrap items-center gap-2">
      <h1 class="mr-auto text-lg font-semibold">Clientes <span v-if="clientes" class="text-sm font-normal text-muted-foreground">{{ clientes.length }}</span></h1>
      <Input v-model="busca" placeholder="Buscar cliente ou fazenda" class="w-full sm:w-64" aria-label="Buscar cliente ou fazenda" />
      <Button @click="abrirNovo"><Plus /> Novo cliente</Button>
    </div>
    <AlertaErros :mensagens="erroCarga" />
    <div v-if="!clientes && !erroCarga.length" class="space-y-3"><Skeleton v-for="i in 3" :key="i" class="h-28 w-full" /></div>

    <article v-for="c in filtrados" :key="c.id" class="rounded-xl border bg-card" :class="{ 'opacity-70': !c.ativo }">
      <div class="flex flex-wrap items-start gap-3 border-b p-4">
        <div class="min-w-0 flex-1">
          <p class="font-semibold">{{ c.nome }} <Badge v-if="!c.ativo" variant="destructive" class="ml-1 align-middle">Suspenso</Badge></p>
          <p class="text-xs text-muted-foreground">
            Cliente desde {{ data(c.created_at) }}<template v-if="c.documento"> · {{ c.documento }}</template>
            · Responsável: {{ c.donos.map((d) => d.nome).join(', ') || 'ainda não entrou' }}
            <template v-if="c.convites_dono.length"> · convite pendente para {{ c.convites_dono[0].nome }}</template>
          </p>
        </div>
        <Button variant="outline" size="sm" @click="exportar(c)"><Download /> Exportar dados</Button>
        <Button variant="outline" size="sm" :class="c.ativo ? 'text-destructive' : ''" @click="alternarSituacao(c)"><Power /> {{ c.ativo ? 'Suspender' : 'Reativar' }}</Button>
      </div>
      <ul class="divide-y">
        <li v-for="f in c.fazendas" :key="f.id" class="flex flex-wrap items-center gap-2 px-4 py-2.5">
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium">{{ f.nome }}</p>
            <p class="text-xs text-muted-foreground">{{ [local(f), `${f.usuarios} usuário(s)`].filter(Boolean).join(' · ') }}</p>
          </div>
          <Button variant="ghost" size="sm" @click="abrirConvite(f)"><UserPlus /> Convidar</Button>
          <Button variant="ghost" size="sm" @click="abrirSuporte(f)"><Headset /> Entrar como suporte</Button>
        </li>
      </ul>
    </article>
    <p v-if="clientes && !filtrados.length" class="py-10 text-center text-sm text-muted-foreground">Nenhum cliente encontrado.</p>
  </main>

  <Dialog v-model:open="novoAberto">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ conviteGerado ? 'Convite do responsável' : 'Novo cliente' }}</DialogTitle>
        <DialogDescription v-if="!conviteGerado">O cliente nasce com a primeira fazenda. O responsável recebe o convite para criar o acesso e passa a cadastrar as outras fazendas e convidar a equipe.</DialogDescription>
        <DialogDescription v-else>Mande o link para {{ conviteGerado.nome }}. Ele vale por 7 dias e só pode ser usado uma vez.</DialogDescription>
      </DialogHeader>
      <LinkCompartilhavel v-if="conviteGerado" :link="conviteGerado.link" :whatsapp="conviteGerado.whatsapp"
                          :aviso="conviteGerado.email ? `Também enviado para ${conviteGerado.email}.` : null" />
      <form v-else id="form-cliente" class="grid gap-4" @submit.prevent="criarCliente">
        <AlertaErros :mensagens="erros" />
        <fieldset class="grid gap-3 sm:grid-cols-2">
          <legend class="mb-1 text-sm font-medium">Cliente</legend>
          <div class="grid gap-1.5 sm:col-span-2"><Label for="n-nome">Nome (produtor ou empresa)</Label><Input id="n-nome" v-model.trim="novo.nome" required :aria-invalid="!!campos.nome" /><p v-if="campos.nome" class="text-xs text-destructive">{{ campos.nome }}</p></div>
          <div class="grid gap-1.5 sm:col-span-2"><Label for="n-doc">CPF ou CNPJ (opcional)</Label><Input id="n-doc" v-model.trim="novo.documento" inputmode="numeric" /></div>
        </fieldset>
        <fieldset class="grid gap-3 sm:grid-cols-[1fr_8rem]">
          <legend class="mb-1 text-sm font-medium">Primeira fazenda</legend>
          <div class="grid gap-1.5 sm:col-span-2"><Label for="n-faz">Nome da fazenda</Label><Input id="n-faz" v-model.trim="novo.fazenda.nome" required :aria-invalid="!!campos['fazenda.nome']" /><p v-if="campos['fazenda.nome']" class="text-xs text-destructive">{{ campos['fazenda.nome'] }}</p></div>
          <div class="grid gap-1.5"><Label for="n-mun">Município</Label><Input id="n-mun" v-model.trim="novo.fazenda.municipio" /></div>
          <div class="grid gap-1.5"><Label for="n-uf">UF</Label><Input id="n-uf" v-model.trim="novo.fazenda.uf" maxlength="2" class="uppercase" :aria-invalid="!!campos['fazenda.uf']" /></div>
        </fieldset>
        <fieldset class="grid gap-3 sm:grid-cols-2">
          <legend class="mb-1 text-sm font-medium">Responsável (recebe o convite)</legend>
          <div class="grid gap-1.5 sm:col-span-2"><Label for="n-dono">Nome</Label><Input id="n-dono" v-model.trim="novo.dono.nome" required :aria-invalid="!!campos['dono.nome']" /></div>
          <div class="grid gap-1.5"><Label for="n-tel">WhatsApp</Label><Input id="n-tel" :model-value="novo.dono.telefone" type="tel" inputmode="tel" placeholder="(34) 99999-1234" @update:model-value="(v) => (novo.dono.telefone = mascaraTelefone(v))" /></div>
          <div class="grid gap-1.5"><Label for="n-email">E-mail (opcional)</Label><Input id="n-email" v-model.trim="novo.dono.email" type="email" :aria-invalid="!!campos['dono.email']" /><p v-if="campos['dono.email']" class="text-xs text-destructive">{{ campos['dono.email'] }}</p></div>
        </fieldset>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="novoAberto = false">{{ conviteGerado ? 'Fechar' : 'Cancelar' }}</Button>
        <Button v-if="!conviteGerado" type="submit" form="form-cliente" :disabled="enviando">Cadastrar e gerar convite</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <Dialog v-model:open="convite.aberto">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Convidar para {{ convite.fazenda?.nome }}</DialogTitle>
        <DialogDescription>Convite com perfil Gestor. Use para mandar um novo link ao responsável ou a outro gestor.</DialogDescription>
      </DialogHeader>
      <LinkCompartilhavel v-if="convite.gerado" :link="convite.gerado.link" :whatsapp="convite.gerado.whatsapp" />
      <form v-else id="form-convite-admin" class="grid gap-3" @submit.prevent="convidar">
        <AlertaErros :mensagens="convite.erros" />
        <div class="grid gap-1.5"><Label for="a-nome">Nome</Label><Input id="a-nome" v-model.trim="convite.nome" required /></div>
        <div class="grid gap-1.5"><Label for="a-tel">WhatsApp</Label><Input id="a-tel" :model-value="convite.telefone" type="tel" inputmode="tel" @update:model-value="(v) => (convite.telefone = mascaraTelefone(v))" /></div>
        <div class="grid gap-1.5"><Label for="a-email">E-mail (opcional)</Label><Input id="a-email" v-model.trim="convite.email" type="email" /></div>
        <label class="flex items-center gap-2 text-sm"><input v-model="convite.dono" type="checkbox" class="size-4 accent-primary" /> Responsável pela conta (cadastra fazendas e exporta dados)</label>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="convite.aberto = false">{{ convite.gerado ? 'Fechar' : 'Cancelar' }}</Button>
        <Button v-if="!convite.gerado" type="submit" form="form-convite-admin">Gerar convite</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <Dialog v-model:open="suporte.aberto">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Entrar como suporte</DialogTitle>
        <DialogDescription>Você entra na {{ suporte.fazenda?.nome }} como gestor por 2 horas. O motivo fica registrado, e o que você alterar entra no histórico com o seu usuário.</DialogDescription>
      </DialogHeader>
      <form id="form-suporte" class="grid gap-3" @submit.prevent="entrarSuporte">
        <AlertaErros :mensagens="suporte.erros" />
        <div class="grid gap-1.5"><Label for="s-motivo">Motivo</Label><Input id="s-motivo" v-model.trim="suporte.motivo" placeholder="ex.: cliente pediu ajuda com o fechamento do mês" required minlength="5" /></div>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="suporte.aberto = false">Cancelar</Button>
        <Button type="submit" form="form-suporte"><Headset /> Entrar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
