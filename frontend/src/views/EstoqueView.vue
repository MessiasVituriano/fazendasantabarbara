<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { AlertTriangle, ArrowDownToLine, ArrowUpFromLine, CalendarX, History, Package, Pencil, Plus, Search, Wallet } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { avisarGuardadoOffline, enviarOuGuardar, useFilaOffline } from '../offline/fila'
import { useDataMinima } from '../composables/useDataMinima'
import { useEnvio } from '../composables/useEnvio'
import { useOpcoes } from '../composables/useOpcoes'
import { useSessao } from '../composables/useSessao'
import { dataBr, hoje, moeda, novoUuid, valorParaApi } from '../formatos'

/**
 * Estoque de medicamentos, vacinas, ração e demais insumos.
 * Os dois perfis consultam e registram entradas e saídas; o gestor cadastra os itens,
 * informa preços, faz ajuste de inventário e cancela movimentos.
 */
const route = useRoute()
const router = useRouter()
const { ehGestor } = useSessao()
const { opcoes } = useOpcoes()
const dataMinima = useDataMinima()
const { enviando, mensagens, campos, enviar } = useEnvio()
const limparErros = () => { mensagens.value = []; campos.value = {} }
const erroCarga = ref([])

const SEL = 'px-3 data-[state=on]:bg-primary data-[state=on]:text-primary-foreground data-[state=on]:hover:bg-primary/90'
const TODOS = 'TODOS'

/** Quantidade sem zeros sobrando: 12, 12,5, 0,125. */
const qtd = (v) => (v == null ? '—' : Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 3 }))

const aba = ref(route.query.aba === 'movimentos' ? 'movimentos' : 'itens')
watch(aba, (v) => {
  if (!v) return (aba.value = 'itens')
  router.replace({ query: { ...route.query, aba: v } })
  if (v === 'movimentos') carregarMovimentos()
})

// ---------------- Itens ----------------
const itens = ref([])
const resumo = ref(null)
const diasAviso = ref(30)
const carregandoItens = ref(false)
const filtros = reactive({ tipo: TODOS, busca: '', alerta: TODOS, inativos: false })

async function carregarItens() {
  carregandoItens.value = true
  try {
    const { data } = await http.get('/estoque/itens', { params: {
      tipo: filtros.tipo === TODOS ? undefined : filtros.tipo,
      busca: filtros.busca || undefined,
      alerta: filtros.alerta === TODOS ? undefined : filtros.alerta,
      inativos: filtros.inativos ? 1 : undefined,
    } })
    itens.value = data.data
    resumo.value = data.meta.resumo
    diasAviso.value = data.meta.dias_aviso_validade
    erroCarga.value = []
  } catch (e) {
    erroCarga.value = e.mensagens
  } finally {
    carregandoItens.value = false
  }
}
carregarItens()
watch(() => [filtros.tipo, filtros.alerta, filtros.inativos], carregarItens)
let espera
watch(() => filtros.busca, () => { clearTimeout(espera); espera = setTimeout(carregarItens, 300) })

const alternarAlerta = (alerta) => { filtros.alerta = filtros.alerta === alerta ? TODOS : alerta }

/** Itens ativos para os formulários (a lista da tela pode estar filtrada). */
const itensAtivos = ref([])
async function carregarItensAtivos() {
  try { itensAtivos.value = (await http.get('/estoque/itens')).data.data } catch { /* a lista da tela já mostra o erro */ }
}
carregarItensAtivos()

function recarregarTudo() {
  carregarItens()
  carregarItensAtivos()
  if (aba.value === 'movimentos') carregarMovimentos()
}

// Registros feitos offline subiram: atualiza saldos e histórico.
const { pendentes } = useFilaOffline()
watch(() => pendentes.value.length, (agora, antes) => { if (agora < antes) recarregarTudo() })

// ---------------- Movimentações ----------------
const movimentos = ref([])
const pagina = ref(1)
const ultimaPagina = ref(1)
const total = ref(0)
const carregandoMov = ref(false)
const filtrosMov = reactive({ tipo: TODOS, item: route.query.item ?? TODOS, inicio: '', fim: '' })

async function carregarMovimentos() {
  carregandoMov.value = true
  try {
    const { data } = await http.get('/estoque/movimentos', { params: {
      tipo: filtrosMov.tipo === TODOS ? undefined : filtrosMov.tipo,
      item_estoque_id: filtrosMov.item === TODOS ? undefined : filtrosMov.item,
      inicio: filtrosMov.inicio || undefined,
      fim: filtrosMov.fim || undefined,
      page: pagina.value,
    } })
    movimentos.value = data.data
    ultimaPagina.value = data.meta.last_page
    total.value = data.meta.total
    erroCarga.value = []
  } catch (e) {
    erroCarga.value = e.mensagens
  } finally {
    carregandoMov.value = false
  }
}
if (aba.value === 'movimentos') carregarMovimentos()
watch(() => [filtrosMov.tipo, filtrosMov.item, filtrosMov.inicio, filtrosMov.fim], () => {
  pagina.value = 1
  router.replace({ query: { ...route.query, item: filtrosMov.item === TODOS ? undefined : filtrosMov.item } })
  carregarMovimentos()
})
function irParaPagina(p) {
  pagina.value = p
  carregarMovimentos()
}
function verHistorico(item) {
  filtrosMov.item = String(item.id)
  aba.value = 'movimentos'
}

const ESTILO_MOV = {
  ENTRADA: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  SAIDA: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  AJUSTE: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
}

// ---------------- Diálogo: movimento ----------------
const movAberto = ref(false)
const formMov = reactive({})
const tiposPermitidos = computed(() => (opcoes.value?.tipo_movimento_estoque ?? []).filter((t) => ehGestor.value || t.value !== 'AJUSTE'))
const itemDoMov = computed(() => itensAtivos.value.find((i) => String(i.id) === formMov.item))

function abrirMovimento(tipo = 'ENTRADA', item = null) {
  limparErros()
  Object.assign(formMov, { tipo, item: item ? String(item.id) : '', data: hoje(), quantidade: '', saldo_contado: '', valor: '', observacao: '' })
  movAberto.value = true
}
function trocarTipoMov(tipo) {
  if (tipo) formMov.tipo = tipo
}

async function salvarMovimento() {
  const item = itemDoMov.value
  const ajuste = formMov.tipo === 'AJUSTE'
  const dados = {
    uuid: novoUuid(),
    item_estoque_id: Number(formMov.item) || null,
    tipo: formMov.tipo,
    data_movimento: formMov.data,
    observacao: formMov.observacao || null,
    ...(ajuste ? { saldo_contado: valorParaApi(formMov.saldo_contado) } : { quantidade: valorParaApi(formMov.quantidade) }),
    ...(ehGestor.value && formMov.tipo === 'ENTRADA' && formMov.valor !== '' ? { valor_unitario: valorParaApi(formMov.valor) } : {}),
  }
  const rotuloTipo = tiposPermitidos.value.find((t) => t.value === formMov.tipo)?.label ?? formMov.tipo
  try {
    const { offline } = await enviar(() => enviarOuGuardar({
      metodo: 'post', url: '/estoque/movimentos', dados,
      descricao: `${rotuloTipo} · ${item?.nome ?? 'item'} · ${ajuste ? `contado ${formMov.saldo_contado}` : formMov.quantidade} ${item?.unidade_sigla ?? ''}`,
    }))
    if (offline) avisarGuardadoOffline(rotuloTipo)
    else toast.success(`${rotuloTipo} registrada: ${item?.nome}`)
    movAberto.value = false
    recarregarTudo()
  } catch { /* exibido no formulário */ }
}

// ---------------- Diálogo: item ----------------
const itemAberto = ref(false)
const itemEditando = ref(null)
const formItem = reactive({})
function abrirItem(item = null) {
  itemEditando.value = item
  limparErros()
  Object.assign(formItem, item
    ? { nome: item.nome, tipo: item.tipo, unidade: item.unidade, minimo: item.estoque_minimo ? qtd(item.estoque_minimo) : '', validade: item.data_validade ?? '', localizacao: item.localizacao ?? '', observacoes: item.observacoes ?? '', ativo: item.ativo }
    : { nome: '', tipo: 'MEDICAMENTO', unidade: 'UN', minimo: '', validade: '', localizacao: '', observacoes: '', ativo: true })
  itemAberto.value = true
}
async function salvarItem() {
  const dados = {
    nome: formItem.nome,
    tipo: formItem.tipo,
    unidade: formItem.unidade,
    estoque_minimo: valorParaApi(formItem.minimo),
    data_validade: formItem.validade || null,
    localizacao: formItem.localizacao || null,
    observacoes: formItem.observacoes || null,
  }
  if (itemEditando.value) dados.ativo = formItem.ativo
  try {
    await enviar(() => itemEditando.value
      ? http.patch(`/estoque/itens/${itemEditando.value.id}`, dados)
      : http.post('/estoque/itens', dados))
    toast.success(itemEditando.value ? 'Item atualizado' : `${formItem.nome} cadastrado. Registre a entrada para lançar o saldo.`)
    itemAberto.value = false
    recarregarTudo()
  } catch { /* exibido no formulário */ }
}

// ---------------- Diálogo: cancelar movimento ----------------
const cancelando = ref(null)
const motivo = ref('')
function abrirCancelamento(m) {
  limparErros()
  motivo.value = ''
  cancelando.value = m
}
async function cancelarMovimento() {
  try {
    await enviar(() => http.post(`/estoque/movimentos/${cancelando.value.id}/cancelamento`, { motivo: motivo.value }))
    toast.success('Movimento cancelado e saldo corrigido')
    cancelando.value = null
    recarregarTudo()
  } catch { /* exibido no diálogo */ }
}
</script>

<template>
  <CabecalhoPagina titulo="Estoque" :contador="aba === 'itens' ? itens.length : total">
    <template #acoes>
      <Button variant="outline" @click="abrirMovimento('SAIDA')"><ArrowUpFromLine /> Saída</Button>
      <Button variant="outline" @click="abrirMovimento('ENTRADA')"><ArrowDownToLine /> Entrada</Button>
      <Button v-if="ehGestor" @click="abrirItem()"><Plus /> <span class="hidden sm:inline">Novo item</span></Button>
    </template>
    <template #filtros>
      <ToggleGroup v-model="aba" type="single" variant="outline" size="sm" aria-label="Seção">
        <ToggleGroupItem value="itens" :class="SEL">Itens</ToggleGroupItem>
        <ToggleGroupItem value="movimentos" :class="SEL">Movimentações</ToggleGroupItem>
      </ToggleGroup>

      <template v-if="aba === 'itens'">
        <Select v-model="filtros.tipo">
          <SelectTrigger size="sm" class="w-full sm:w-52" aria-label="Tipo"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem :value="TODOS">Todos os tipos</SelectItem>
            <SelectItem v-for="t in opcoes?.tipo_item_estoque" :key="t.value" :value="t.value">{{ t.label }}</SelectItem>
          </SelectContent>
        </Select>
        <div class="relative w-full sm:w-64">
          <Search class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input v-model="filtros.busca" placeholder="Buscar por nome ou local" class="pl-8" />
        </div>
        <label v-if="ehGestor" class="flex items-center gap-2 text-sm pointer-coarse:min-h-11">
          <input v-model="filtros.inativos" type="checkbox" class="size-4 accent-primary" /> Mostrar desativados
        </label>
      </template>

      <template v-else>
        <ToggleGroup :model-value="filtrosMov.tipo" type="single" variant="outline" size="sm" aria-label="Tipo de movimento" @update:model-value="(v) => v && (filtrosMov.tipo = v)">
          <ToggleGroupItem :value="TODOS" :class="SEL">Todos</ToggleGroupItem>
          <ToggleGroupItem v-for="t in opcoes?.tipo_movimento_estoque" :key="t.value" :value="t.value" :class="SEL">{{ t.value === 'AJUSTE' ? 'Ajustes' : t.label }}</ToggleGroupItem>
        </ToggleGroup>
        <Select v-model="filtrosMov.item">
          <SelectTrigger size="sm" class="w-full sm:w-56" aria-label="Item"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem :value="TODOS">Todos os itens</SelectItem>
            <SelectItem v-for="i in itensAtivos" :key="i.id" :value="String(i.id)">{{ i.nome }}</SelectItem>
          </SelectContent>
        </Select>
        <div class="flex items-center gap-2 text-sm">
          <Input v-model="filtrosMov.inicio" type="date" class="h-8 w-36" aria-label="De" />
          <span class="text-muted-foreground">até</span>
          <Input v-model="filtrosMov.fim" type="date" class="h-8 w-36" aria-label="Até" />
        </div>
      </template>
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erroCarga" />

    <!-- ITENS -->
    <template v-if="aba === 'itens'">
      <div v-if="resumo" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <button class="rounded-xl border bg-card p-4 text-left transition hover:shadow-sm" :class="{ 'ring-2 ring-primary': filtros.alerta === TODOS }" @click="filtros.alerta = TODOS">
          <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><Package class="size-3.5" /> Itens ativos</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums">{{ resumo.itens }}</p>
        </button>
        <button class="rounded-xl border bg-card p-4 text-left transition hover:shadow-sm" :class="{ 'ring-2 ring-primary': filtros.alerta === 'REPOR' }" @click="alternarAlerta('REPOR')">
          <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><AlertTriangle class="size-3.5" /> Para repor</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums" :class="{ 'text-destructive': resumo.repor }">{{ resumo.repor }}</p>
        </button>
        <button class="rounded-xl border bg-card p-4 text-left transition hover:shadow-sm" :class="{ 'ring-2 ring-primary': filtros.alerta === 'VALIDADE' }" @click="alternarAlerta('VALIDADE')">
          <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><CalendarX class="size-3.5" /> Validade</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums" :class="{ 'text-destructive': resumo.vencidos }">
            {{ resumo.vencidos + resumo.vencendo }}
          </p>
          <p class="text-xs text-muted-foreground">{{ resumo.vencidos }} vencido(s) · {{ resumo.vencendo }} em {{ diasAviso }} dias</p>
        </button>
        <div v-if="ehGestor" class="rounded-xl border bg-card p-4">
          <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><Wallet class="size-3.5" /> Valor em estoque</p>
          <p class="mt-1 text-2xl font-semibold tabular-nums">{{ moeda(resumo.valor_total) }}</p>
          <p class="text-xs text-muted-foreground">pelo preço da última entrada</p>
        </div>
      </div>

      <!-- celular: cartões com saldo, alertas e ações à vista -->
      <ul class="divide-y rounded-xl border bg-card md:hidden" :class="{ 'opacity-50': carregandoItens }">
        <li v-for="i in itens" :key="i.id" class="space-y-2 px-4 py-3" :class="{ 'opacity-60': !i.ativo }">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium">{{ i.nome }}</p>
              <p class="text-xs text-muted-foreground">{{ [i.tipo_label, i.localizacao].filter(Boolean).join(' · ') }}</p>
            </div>
            <p class="shrink-0 text-right tabular-nums">
              <span class="text-lg font-semibold" :class="{ 'text-destructive': i.abaixo_minimo }">{{ qtd(i.saldo) }}</span>
              <span class="ml-1 text-xs text-muted-foreground">{{ i.unidade_sigla }}</span>
              <span v-if="i.estoque_minimo" class="block text-xs text-muted-foreground">mín. {{ qtd(i.estoque_minimo) }}</span>
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-1.5">
            <Badge v-if="!i.ativo" variant="outline">Desativado</Badge>
            <Badge v-if="i.abaixo_minimo" variant="destructive">{{ i.saldo > 0 ? 'Repor' : 'Sem estoque' }}</Badge>
            <Badge v-if="i.situacao_validade === 'VENCIDO'" variant="destructive">Vencido em {{ dataBr(i.data_validade) }}</Badge>
            <Badge v-else-if="i.situacao_validade === 'VENCE_EM_BREVE'" class="border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">Vence {{ dataBr(i.data_validade) }}</Badge>
            <span v-if="ehGestor && i.valor_estoque != null" class="text-xs text-muted-foreground tabular-nums">{{ moeda(i.valor_estoque) }}</span>
            <div class="ml-auto flex">
              <template v-if="i.ativo">
                <Button variant="ghost" size="icon" :aria-label="`Entrada de ${i.nome}`" @click="abrirMovimento('ENTRADA', i)"><ArrowDownToLine /></Button>
                <Button variant="ghost" size="icon" :aria-label="`Saída de ${i.nome}`" :disabled="!i.saldo" @click="abrirMovimento('SAIDA', i)"><ArrowUpFromLine /></Button>
              </template>
              <Button variant="ghost" size="icon" :aria-label="`Histórico de ${i.nome}`" @click="verHistorico(i)"><History /></Button>
              <Button v-if="ehGestor" variant="ghost" size="icon" :aria-label="`Editar ${i.nome}`" @click="abrirItem(i)"><Pencil /></Button>
            </div>
          </div>
        </li>
        <li v-if="!itens.length && !carregandoItens" class="px-4 py-3 text-sm text-muted-foreground">Nenhum item encontrado.</li>
      </ul>

      <div class="hidden overflow-x-auto rounded-xl border bg-card md:block" :class="{ 'opacity-50': carregandoItens }">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Item</TableHead>
              <TableHead class="text-right">Saldo</TableHead>
              <TableHead class="text-right">Mínimo</TableHead>
              <TableHead>Validade</TableHead>
              <TableHead v-if="ehGestor" class="text-right">Valor</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="i in itens" :key="i.id" :class="{ 'opacity-60': !i.ativo }">
              <TableCell>
                <p class="font-medium">{{ i.nome }}</p>
                <p class="text-xs text-muted-foreground">{{ [i.tipo_label, i.localizacao].filter(Boolean).join(' · ') }}</p>
                <Badge v-if="!i.ativo" variant="outline" class="mt-0.5">Desativado</Badge>
              </TableCell>
              <TableCell class="text-right whitespace-nowrap tabular-nums">
                <span class="font-semibold" :class="{ 'text-destructive': i.abaixo_minimo }">{{ qtd(i.saldo) }}</span>
                <span class="ml-1 text-xs text-muted-foreground">{{ i.unidade_sigla }}</span>
                <p v-if="i.abaixo_minimo"><Badge variant="destructive" class="mt-0.5">{{ i.saldo > 0 ? 'Repor' : 'Sem estoque' }}</Badge></p>
              </TableCell>
              <TableCell class="text-right text-sm whitespace-nowrap text-muted-foreground tabular-nums">{{ i.estoque_minimo ? `${qtd(i.estoque_minimo)} ${i.unidade_sigla}` : '—' }}</TableCell>
              <TableCell class="whitespace-nowrap">
                <Badge v-if="i.situacao_validade === 'VENCIDO'" variant="destructive">Vencido em {{ dataBr(i.data_validade) }}</Badge>
                <Badge v-else-if="i.situacao_validade === 'VENCE_EM_BREVE'" class="border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">Vence {{ dataBr(i.data_validade) }}</Badge>
                <span v-else class="text-sm text-muted-foreground tabular-nums">{{ dataBr(i.data_validade) }}</span>
              </TableCell>
              <TableCell v-if="ehGestor" class="text-right text-sm whitespace-nowrap tabular-nums">
                {{ moeda(i.valor_estoque) }}
                <p v-if="i.custo_unitario != null" class="text-xs text-muted-foreground">{{ moeda(i.custo_unitario) }}/{{ i.unidade_sigla }}</p>
              </TableCell>
              <TableCell class="text-right whitespace-nowrap">
                <template v-if="i.ativo">
                  <Button variant="ghost" size="icon" :aria-label="`Entrada de ${i.nome}`" title="Entrada" @click="abrirMovimento('ENTRADA', i)"><ArrowDownToLine /></Button>
                  <Button variant="ghost" size="icon" :aria-label="`Saída de ${i.nome}`" title="Saída" :disabled="!i.saldo" @click="abrirMovimento('SAIDA', i)"><ArrowUpFromLine /></Button>
                </template>
                <Button variant="ghost" size="icon" :aria-label="`Histórico de ${i.nome}`" title="Histórico" @click="verHistorico(i)"><History /></Button>
                <Button v-if="ehGestor" variant="ghost" size="icon" :aria-label="`Editar ${i.nome}`" title="Editar" @click="abrirItem(i)"><Pencil /></Button>
              </TableCell>
            </TableRow>
            <TableRow v-if="!itens.length && !carregandoItens">
              <TableCell :colspan="ehGestor ? 6 : 5" class="text-muted-foreground">
                {{ filtros.alerta !== TODOS || filtros.busca || filtros.tipo !== TODOS ? 'Nenhum item encontrado com estes filtros.' : ehGestor ? 'Nenhum item cadastrado. Use "Novo item" para começar.' : 'Nenhum item cadastrado. Peça ao gestor para cadastrar os itens do estoque.' }}
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
    </template>

    <!-- MOVIMENTAÇÕES -->
    <template v-else>
      <!-- celular: cartões com a quantidade em destaque -->
      <ul class="divide-y rounded-xl border bg-card md:hidden" :class="{ 'opacity-50': carregandoMov }">
        <li v-for="m in movimentos" :key="m.id" class="space-y-1 px-4 py-3" :class="{ 'opacity-60': m.cancelado }">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium" :class="{ 'line-through': m.cancelado }">{{ m.item?.nome }}</p>
              <p class="text-xs text-muted-foreground tabular-nums">{{ dataBr(m.data_movimento) }}<template v-if="m.usuario"> · {{ m.usuario }}</template></p>
            </div>
            <p class="shrink-0 text-right font-semibold tabular-nums" :class="m.efeito_no_saldo < 0 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400'">
              {{ m.efeito_no_saldo > 0 ? '+' : '' }}{{ qtd(m.efeito_no_saldo) }}<span class="ml-1 text-xs font-normal text-muted-foreground">{{ m.item?.unidade_sigla }}</span>
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <Badge class="border-transparent" :class="ESTILO_MOV[m.tipo]">{{ m.tipo_label }}</Badge>
            <span v-if="ehGestor && m.valor_total != null" class="text-xs text-muted-foreground tabular-nums">{{ moeda(m.valor_total) }}</span>
            <Button v-if="ehGestor && !m.cancelado" variant="ghost" size="sm" class="ml-auto text-destructive" @click="abrirCancelamento(m)">Cancelar</Button>
          </div>
          <p v-if="m.observacao" class="text-sm text-muted-foreground">{{ m.observacao }}</p>
          <p v-if="m.cancelado" class="text-xs text-destructive">Cancelado: {{ m.motivo_cancelamento }}</p>
        </li>
        <li v-if="!movimentos.length && !carregandoMov" class="px-4 py-3 text-sm text-muted-foreground">Nenhuma movimentação encontrada.</li>
      </ul>

      <div class="hidden overflow-x-auto rounded-xl border bg-card md:block" :class="{ 'opacity-50': carregandoMov }">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Data</TableHead><TableHead>Item</TableHead><TableHead>Tipo</TableHead>
              <TableHead class="text-right">Quantidade</TableHead>
              <TableHead v-if="ehGestor" class="text-right">Valor</TableHead>
              <TableHead>Observação</TableHead>
              <TableHead v-if="ehGestor" />
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="m in movimentos" :key="m.id" :class="{ 'opacity-60': m.cancelado }">
              <TableCell class="text-sm whitespace-nowrap tabular-nums">{{ dataBr(m.data_movimento) }}</TableCell>
              <TableCell class="font-medium" :class="{ 'line-through': m.cancelado }">{{ m.item?.nome }}</TableCell>
              <TableCell class="whitespace-nowrap">
                <Badge class="border-transparent" :class="ESTILO_MOV[m.tipo]">{{ m.tipo_label }}</Badge>
                <p v-if="m.cancelado" class="mt-0.5 text-xs text-destructive">Cancelado: {{ m.motivo_cancelamento }}</p>
              </TableCell>
              <TableCell class="text-right whitespace-nowrap tabular-nums" :class="m.efeito_no_saldo < 0 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400'">
                {{ m.efeito_no_saldo > 0 ? '+' : '' }}{{ qtd(m.efeito_no_saldo) }}<span class="ml-1 text-xs text-muted-foreground">{{ m.item?.unidade_sigla }}</span>
              </TableCell>
              <TableCell v-if="ehGestor" class="text-right text-sm whitespace-nowrap tabular-nums">
                {{ moeda(m.valor_total) }}
                <p v-if="m.valor_unitario != null" class="text-xs text-muted-foreground">{{ moeda(m.valor_unitario) }}/{{ m.item?.unidade_sigla }}</p>
              </TableCell>
              <TableCell class="max-w-64 text-sm">
                <p class="truncate">{{ m.observacao || '—' }}</p>
                <p v-if="m.usuario" class="text-xs text-muted-foreground">por {{ m.usuario }}</p>
              </TableCell>
              <TableCell v-if="ehGestor" class="text-right">
                <Button v-if="!m.cancelado" variant="ghost" size="sm" class="text-destructive" @click="abrirCancelamento(m)">Cancelar</Button>
              </TableCell>
            </TableRow>
            <TableRow v-if="!movimentos.length && !carregandoMov"><TableCell :colspan="ehGestor ? 7 : 5" class="text-muted-foreground">Nenhuma movimentação encontrada.</TableCell></TableRow>
          </TableBody>
        </Table>
      </div>
      <div v-if="ultimaPagina > 1" class="flex items-center justify-between gap-2">
        <p class="text-sm text-muted-foreground tabular-nums">{{ total }} movimentações · página {{ pagina }} de {{ ultimaPagina }}</p>
        <div class="flex gap-2">
          <Button variant="outline" size="sm" :disabled="pagina <= 1" @click="irParaPagina(pagina - 1)">Anterior</Button>
          <Button variant="outline" size="sm" :disabled="pagina >= ultimaPagina" @click="irParaPagina(pagina + 1)">Próxima</Button>
        </div>
      </div>
    </template>
  </main>

  <!-- Diálogo: entrada / saída / ajuste -->
  <Dialog v-model:open="movAberto">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>Movimentar estoque</DialogTitle>
        <DialogDescription>
          <template v-if="formMov.tipo === 'ENTRADA'">Compra, doação ou recebimento de material.</template>
          <template v-else-if="formMov.tipo === 'SAIDA'">Uso no rebanho, perda ou descarte.</template>
          <template v-else>Informe quanto foi contado: a diferença para o saldo do sistema é lançada como ajuste.</template>
        </DialogDescription>
      </DialogHeader>
      <form id="form-movimento" class="grid gap-4" @submit.prevent="salvarMovimento">
        <AlertaErros :mensagens="Object.keys(campos).length ? [] : mensagens" />
        <ToggleGroup :model-value="formMov.tipo" type="single" variant="outline" class="w-full" @update:model-value="trocarTipoMov">
          <ToggleGroupItem v-for="t in tiposPermitidos" :key="t.value" :value="t.value" :class="[SEL, 'flex-1']">{{ t.value === 'AJUSTE' ? 'Ajuste' : t.label }}</ToggleGroupItem>
        </ToggleGroup>

        <div class="grid gap-1.5">
          <Label>Item</Label>
          <Select v-model="formMov.item">
            <SelectTrigger class="w-full" :aria-invalid="!!campos.item_estoque_id"><SelectValue placeholder="Selecione…" /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="i in itensAtivos" :key="i.id" :value="String(i.id)">{{ i.nome }} · {{ qtd(i.saldo) }} {{ i.unidade_sigla }}</SelectItem>
            </SelectContent>
          </Select>
          <p v-if="campos.item_estoque_id" class="text-xs text-destructive">{{ campos.item_estoque_id }}</p>
          <p v-else-if="itemDoMov" class="text-xs text-muted-foreground">Saldo atual: {{ qtd(itemDoMov.saldo) }} {{ itemDoMov.unidade_sigla }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="grid gap-1.5">
            <Label for="mov-data">Data</Label>
            <Input id="mov-data" v-model="formMov.data" type="date" :min="dataMinima" :max="hoje()" required :aria-invalid="!!campos.data_movimento" />
            <p v-if="campos.data_movimento" class="text-xs text-destructive">{{ campos.data_movimento }}</p>
          </div>
          <div v-if="formMov.tipo === 'AJUSTE'" class="grid gap-1.5">
            <Label for="mov-contado">Saldo contado <span v-if="itemDoMov" class="text-muted-foreground">({{ itemDoMov.unidade_sigla }})</span></Label>
            <Input id="mov-contado" v-model="formMov.saldo_contado" inputmode="decimal" required :aria-invalid="!!campos.saldo_contado" />
            <p v-if="campos.saldo_contado" class="text-xs text-destructive">{{ campos.saldo_contado }}</p>
          </div>
          <div v-else class="grid gap-1.5">
            <Label for="mov-qtd">Quantidade <span v-if="itemDoMov" class="text-muted-foreground">({{ itemDoMov.unidade_sigla }})</span></Label>
            <Input id="mov-qtd" v-model="formMov.quantidade" inputmode="decimal" required :aria-invalid="!!campos.quantidade" />
            <p v-if="campos.quantidade" class="text-xs text-destructive">{{ campos.quantidade }}</p>
          </div>
          <div v-if="ehGestor && formMov.tipo === 'ENTRADA'" class="grid gap-1.5">
            <Label for="mov-valor">Valor unitário (R$)</Label>
            <Input id="mov-valor" v-model="formMov.valor" inputmode="decimal" placeholder="Opcional" :aria-invalid="!!campos.valor_unitario" />
            <p v-if="campos.valor_unitario" class="text-xs text-destructive">{{ campos.valor_unitario }}</p>
          </div>
        </div>

        <div class="grid gap-1.5">
          <Label for="mov-obs">Observação</Label>
          <Input id="mov-obs" v-model.trim="formMov.observacao" maxlength="255" :placeholder="formMov.tipo === 'SAIDA' ? 'Ex.: lote da recria, vaca 123, descarte' : formMov.tipo === 'ENTRADA' ? 'Ex.: fornecedor, nota fiscal, lote' : 'Ex.: contagem mensal'" />
        </div>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="movAberto = false">Cancelar</Button>
        <Button type="submit" form="form-movimento" :disabled="enviando">{{ enviando ? 'Salvando…' : 'Registrar' }}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- Diálogo: item -->
  <Dialog v-model:open="itemAberto">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ itemEditando ? 'Editar item' : 'Novo item' }}</DialogTitle>
        <DialogDescription>O saldo não é digitado aqui: ele muda pelas entradas, saídas e ajustes.</DialogDescription>
      </DialogHeader>
      <form id="form-item" class="grid gap-4" @submit.prevent="salvarItem">
        <AlertaErros :mensagens="Object.keys(campos).length ? [] : mensagens" />
        <div class="grid gap-1.5">
          <Label for="item-nome">Nome</Label>
          <Input id="item-nome" v-model.trim="formItem.nome" maxlength="100" required placeholder="Ex.: Ivermectina 1% 500 mL, Ração lactação 22%" :aria-invalid="!!campos.nome" />
          <p v-if="campos.nome" class="text-xs text-destructive">{{ campos.nome }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="grid gap-1.5">
            <Label>Tipo</Label>
            <Select v-model="formItem.tipo">
              <SelectTrigger class="w-full" :aria-invalid="!!campos.tipo"><SelectValue /></SelectTrigger>
              <SelectContent><SelectItem v-for="t in opcoes?.tipo_item_estoque" :key="t.value" :value="t.value">{{ t.label }}</SelectItem></SelectContent>
            </Select>
          </div>
          <div class="grid gap-1.5">
            <Label>Unidade</Label>
            <Select v-model="formItem.unidade">
              <SelectTrigger class="w-full" :aria-invalid="!!campos.unidade"><SelectValue /></SelectTrigger>
              <SelectContent><SelectItem v-for="u in opcoes?.unidade_estoque" :key="u.value" :value="u.value">{{ u.label }}</SelectItem></SelectContent>
            </Select>
            <p v-if="campos.unidade" class="text-xs text-destructive">{{ campos.unidade }}</p>
          </div>
          <div class="grid gap-1.5">
            <Label for="item-minimo">Estoque mínimo</Label>
            <Input id="item-minimo" v-model="formItem.minimo" inputmode="decimal" placeholder="Sem alerta" :aria-invalid="!!campos.estoque_minimo" />
            <p v-if="campos.estoque_minimo" class="text-xs text-destructive">{{ campos.estoque_minimo }}</p>
            <p v-else class="text-xs text-muted-foreground">Com o saldo nesse valor ou abaixo, o item aparece em "Para repor".</p>
          </div>
          <div class="grid gap-1.5">
            <Label for="item-validade">Validade do lote em uso</Label>
            <Input id="item-validade" v-model="formItem.validade" type="date" :aria-invalid="!!campos.data_validade" />
            <p v-if="campos.data_validade" class="text-xs text-destructive">{{ campos.data_validade }}</p>
          </div>
        </div>
        <div class="grid gap-1.5">
          <Label for="item-local">Localização</Label>
          <Input id="item-local" v-model.trim="formItem.localizacao" maxlength="100" placeholder="Ex.: farmácia, geladeira, galpão de ração" />
        </div>
        <div class="grid gap-1.5">
          <Label for="item-obs">Observações</Label>
          <Textarea id="item-obs" v-model="formItem.observacoes" rows="2" maxlength="2000" placeholder="Ex.: dose, modo de uso, fornecedor habitual" />
        </div>
        <label v-if="itemEditando" class="flex items-center gap-2 text-sm">
          <input v-model="formItem.ativo" type="checkbox" class="size-4 accent-primary" /> Item ativo
          <span class="text-xs text-muted-foreground">(desativado sai das listas, mas o histórico continua)</span>
        </label>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="itemAberto = false">Cancelar</Button>
        <Button type="submit" form="form-item" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- Diálogo: cancelar movimento -->
  <Dialog :open="!!cancelando" @update:open="(v) => !v && (cancelando = null)">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Cancelar movimento?</DialogTitle>
        <DialogDescription v-if="cancelando">
          {{ cancelando.tipo_label }} de {{ qtd(Math.abs(cancelando.quantidade)) }} {{ cancelando.item?.unidade_sigla }} de {{ cancelando.item?.nome }} em {{ dataBr(cancelando.data_movimento) }}.
          O saldo é corrigido e o movimento continua no histórico como cancelado.
        </DialogDescription>
      </DialogHeader>
      <form id="form-cancelamento" class="grid gap-1.5" @submit.prevent="cancelarMovimento">
        <AlertaErros :mensagens="Object.keys(campos).length ? [] : mensagens" />
        <Label for="cancel-motivo">Motivo</Label>
        <Input id="cancel-motivo" v-model.trim="motivo" maxlength="255" required :aria-invalid="!!campos.motivo" />
        <p v-if="campos.motivo" class="text-xs text-destructive">{{ campos.motivo }}</p>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="cancelando = null">Voltar</Button>
        <Button type="submit" form="form-cancelamento" variant="destructive" :disabled="enviando">Cancelar movimento</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
