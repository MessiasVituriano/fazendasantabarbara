<script setup>
import { computed, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTransition } from '@vueuse/core'
import { AlertTriangle, ArrowDownToLine, ArrowUpFromLine, CalendarClock, CalendarX, Gauge, History, Package, Pencil, Plus, Search, Wallet } from '@lucide/vue'
import { toast } from 'vue-sonner'
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

const ESTILO_MOV = { ENTRADA: 'lua-badge--success', SAIDA: 'lua-badge--warning', AJUSTE: 'lua-badge--info' }

// ---------------- Painéis dinâmicos (só leitura, calculados dos itens já carregados) ----------------
/** "Hoje" reativo: os prazos se atualizam sozinhos na virada do dia com a tela aberta. */
const dataHoje = ref(hoje())
const relogio = setInterval(() => { dataHoje.value = hoje() }, 60_000)
onUnmounted(() => clearInterval(relogio))

/** Barras começam vazias e crescem até o valor depois da primeira carga. */
const animar = ref(false)
watch(itensAtivos, () => requestAnimationFrame(() => { animar.value = true }), { once: true })

const DIA = 86_400_000
const utc = (iso) => { const [a, m, d] = iso.slice(0, 10).split('-').map(Number); return Date.UTC(a, m - 1, d) }
const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`

/** Prazo de validade legível e com tom: segue a situação calculada pelo servidor. */
function prazo(i) {
  if (!i.data_validade) return null
  const dias = Math.round((utc(i.data_validade) - utc(dataHoje.value)) / DIA)
  const tom = i.situacao_validade === 'VENCIDO' ? 'danger' : i.situacao_validade === 'VENCE_EM_BREVE' ? 'warning' : 'success'
  let texto
  if (dias < 0) texto = `Vencido há ${plural(-dias, 'dia', 'dias')}`
  else if (dias === 0) texto = 'Vence hoje'
  else if (dias === 1) texto = 'Vence amanhã'
  else if (dias <= 60) texto = `Vence em ${dias} dias`
  else texto = `Vence em ${plural(Math.round(dias / 30), 'mês', 'meses')}`
  // Barra: quanto do prazo de aviso ainda resta (cheia = folgado, vazia = vencido).
  const janela = Math.max(diasAviso.value * 3, 1)
  const resta = Math.max(0, Math.min(1, dias / janela))
  return { dias, texto, tom, resta }
}

/** Nível do saldo frente ao mínimo: escala em 3× o mínimo, com marcador do mínimo. */
function nivel(i) {
  const minimo = Number(i.estoque_minimo) || 0
  const saldo = Number(i.saldo) || 0
  const tom = saldo <= 0 || i.abaixo_minimo ? 'danger' : minimo && saldo <= minimo * 1.5 ? 'warning' : 'success'
  if (!minimo) return { tom, pct: null, marca: null }
  const escala = Math.max(saldo, minimo * 3)
  return { tom, pct: saldo / escala, marca: minimo / escala }
}

const proximasValidades = computed(() => itensAtivos.value
  .filter((i) => i.data_validade)
  .map((i) => ({ item: i, p: prazo(i) }))
  .sort((a, b) => a.p.dias - b.p.dias)
  .slice(0, 6))
const contagemValidade = computed(() => {
  const c = { danger: 0, warning: 0, success: 0, sem: 0 }
  for (const i of itensAtivos.value) { const p = prazo(i); p ? c[p.tom]++ : c.sem++ }
  return c
})

const niveisCriticos = computed(() => itensAtivos.value
  .filter((i) => Number(i.estoque_minimo) > 0)
  .map((i) => ({ item: i, n: nivel(i), razao: Number(i.saldo) / Number(i.estoque_minimo) }))
  .sort((a, b) => a.razao - b.razao)
  .slice(0, 6))
const semEstoque = computed(() => itensAtivos.value.filter((i) => Number(i.saldo) <= 0).length)

// Contadores dos cartões sobem animados até o valor.
const animado = (fonte) => useTransition(fonte, { duration: 650 })
const kpiItens = animado(computed(() => resumo.value?.itens ?? 0))
const kpiRepor = animado(computed(() => resumo.value?.repor ?? 0))
const kpiValidade = animado(computed(() => (resumo.value?.vencidos ?? 0) + (resumo.value?.vencendo ?? 0)))
const kpiValor = animado(computed(() => resumo.value?.valor_total ?? 0))

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
 <div class="lua-estoque min-h-screen">
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

    <div class="flex flex-wrap items-end justify-between gap-2">
      <div>
        <p class="lua-eyebrow">Gestão de estoque</p>
        <h2 class="lua-page-title">{{ aba === 'itens' ? 'Saldos e validades' : 'Histórico de movimentações' }}</h2>
        <p class="lua-page-subtitle">Medicamentos, vacinas, ração e demais insumos da fazenda</p>
      </div>
      <p class="lua-page-subtitle tabular-nums">Hoje, {{ dataBr(dataHoje) }}</p>
    </div>

    <!-- ITENS -->
    <template v-if="aba === 'itens'">
      <div v-if="resumo" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <button class="lua-kpi lua-kpi--botao" :class="{ 'lua-kpi--ativo': filtros.alerta === TODOS }" @click="filtros.alerta = TODOS">
          <span class="lua-kpi-label"><Package class="size-3.5 lua-tom--primary" /> Itens ativos</span>
          <span class="lua-kpi-value tabular-nums">{{ Math.round(kpiItens) }}</span>
          <span class="lua-kpi-sub">{{ contagemValidade.sem }} sem validade informada</span>
        </button>
        <button class="lua-kpi lua-kpi--botao" :class="{ 'lua-kpi--ativo': filtros.alerta === 'REPOR', 'lua-kpi--alerta': resumo.repor }" @click="alternarAlerta('REPOR')">
          <span class="lua-kpi-label"><AlertTriangle class="size-3.5 lua-tom--danger" /> Para repor</span>
          <span class="lua-kpi-value tabular-nums" :class="{ 'lua-tom--danger': resumo.repor }">{{ Math.round(kpiRepor) }}</span>
          <span class="lua-kpi-sub">{{ semEstoque }} sem estoque</span>
        </button>
        <button class="lua-kpi lua-kpi--botao" :class="{ 'lua-kpi--ativo': filtros.alerta === 'VALIDADE', 'lua-kpi--alerta': resumo.vencidos }" @click="alternarAlerta('VALIDADE')">
          <span class="lua-kpi-label"><CalendarX class="size-3.5 lua-tom--warning" /> Validade</span>
          <span class="lua-kpi-value tabular-nums" :class="{ 'lua-tom--danger': resumo.vencidos }">{{ Math.round(kpiValidade) }}</span>
          <span class="lua-kpi-sub">{{ resumo.vencidos }} vencido(s) · {{ resumo.vencendo }} em {{ diasAviso }} dias</span>
        </button>
        <div v-if="ehGestor" class="lua-kpi">
          <span class="lua-kpi-label"><Wallet class="size-3.5 lua-tom--success" /> Valor em estoque</span>
          <span class="lua-kpi-value tabular-nums">{{ moeda(kpiValor) }}</span>
          <span class="lua-kpi-sub">pelo preço da última entrada</span>
        </div>
      </div>

      <!-- Painéis vivos: prazos de validade e níveis frente ao mínimo -->
      <div v-if="itensAtivos.length" class="grid gap-3 lg:grid-cols-2">
        <section class="lua-card">
          <header class="lua-card-header">
            <div>
              <h3 class="lua-section-title"><CalendarClock class="size-4 lua-tom--primary" /> Próximas validades</h3>
              <p class="lua-section-subtitle">Ordenadas pelo vencimento mais próximo</p>
            </div>
            <div class="flex flex-wrap justify-end gap-1">
              <span v-if="contagemValidade.danger" class="lua-badge lua-badge--danger tabular-nums">{{ contagemValidade.danger }} vencido(s)</span>
              <span v-if="contagemValidade.warning" class="lua-badge lua-badge--warning tabular-nums">{{ contagemValidade.warning }} perto</span>
              <span class="lua-badge lua-badge--success tabular-nums">{{ contagemValidade.success }} em dia</span>
            </div>
          </header>
          <TransitionGroup v-if="proximasValidades.length" tag="ul" name="lua-lista" class="lua-lista">
            <li v-for="{ item: i, p } in proximasValidades" :key="i.id" class="lua-linha">
              <div class="min-w-0 flex-1">
                <div class="flex items-baseline justify-between gap-2">
                  <p class="truncate text-sm font-medium">{{ i.nome }}</p>
                  <span class="lua-badge shrink-0" :class="`lua-badge--${p.tom}`">{{ p.texto }}</span>
                </div>
                <div class="lua-meter mt-1.5" :title="`Validade ${dataBr(i.data_validade)}`">
                  <div class="lua-meter-fill" :class="`lua-fill--${p.tom}`" :style="{ width: `${animar ? Math.max(p.resta * 100, p.dias < 0 ? 100 : 3) : 0}%` }" />
                </div>
                <p class="mt-1 text-xs text-muted-foreground tabular-nums">{{ dataBr(i.data_validade) }} · {{ qtd(i.saldo) }} {{ i.unidade_sigla }}<template v-if="i.localizacao"> · {{ i.localizacao }}</template></p>
              </div>
            </li>
          </TransitionGroup>
          <p v-else class="px-4 py-6 text-sm text-muted-foreground">Nenhum item com validade informada.</p>
          <button v-if="resumo && resumo.vencidos + resumo.vencendo" class="lua-card-footer" @click="alternarAlerta('VALIDADE')">
            {{ filtros.alerta === 'VALIDADE' ? 'Mostrar todos os itens' : 'Filtrar itens com alerta de validade' }}
          </button>
        </section>

        <section class="lua-card">
          <header class="lua-card-header">
            <div>
              <h3 class="lua-section-title"><Gauge class="size-4 lua-tom--primary" /> Níveis de estoque</h3>
              <p class="lua-section-subtitle">Saldo frente ao mínimo, do mais crítico ao mais folgado</p>
            </div>
            <span v-if="resumo?.repor" class="lua-badge lua-badge--danger tabular-nums">{{ resumo.repor }} para repor</span>
          </header>
          <TransitionGroup v-if="niveisCriticos.length" tag="ul" name="lua-lista" class="lua-lista">
            <li v-for="{ item: i, n } in niveisCriticos" :key="i.id" class="lua-linha">
              <div class="min-w-0 flex-1">
                <div class="flex items-baseline justify-between gap-2">
                  <p class="truncate text-sm font-medium">{{ i.nome }}</p>
                  <p class="shrink-0 text-sm tabular-nums">
                    <span class="font-semibold" :class="`lua-tom--${n.tom}`">{{ qtd(i.saldo) }}</span>
                    <span class="text-xs text-muted-foreground"> / mín. {{ qtd(i.estoque_minimo) }} {{ i.unidade_sigla }}</span>
                  </p>
                </div>
                <div class="lua-meter mt-1.5">
                  <div class="lua-meter-fill" :class="`lua-fill--${n.tom}`" :style="{ width: `${animar ? n.pct * 100 : 0}%` }" />
                  <div class="lua-meter-marca" :style="{ left: `${n.marca * 100}%` }" title="Estoque mínimo" />
                </div>
              </div>
            </li>
          </TransitionGroup>
          <p v-else class="px-4 py-6 text-sm text-muted-foreground">Nenhum item com estoque mínimo definido.</p>
          <button v-if="resumo?.repor" class="lua-card-footer" @click="alternarAlerta('REPOR')">
            {{ filtros.alerta === 'REPOR' ? 'Mostrar todos os itens' : 'Filtrar itens para repor' }}
          </button>
        </section>
      </div>

      <!-- celular: cartões com saldo, alertas e ações à vista -->
      <ul class="lua-card divide-y md:hidden" :class="{ 'opacity-50': carregandoItens }">
        <li v-for="i in itens" :key="i.id" class="space-y-2 px-4 py-3" :class="{ 'opacity-60': !i.ativo }">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium">{{ i.nome }}</p>
              <p class="text-xs text-muted-foreground">{{ [i.tipo_label, i.localizacao].filter(Boolean).join(' · ') }}</p>
            </div>
            <p class="shrink-0 text-right tabular-nums">
              <span class="text-lg font-semibold" :class="{ 'lua-tom--danger': i.abaixo_minimo }">{{ qtd(i.saldo) }}</span>
              <span class="ml-1 text-xs text-muted-foreground">{{ i.unidade_sigla }}</span>
              <span v-if="i.estoque_minimo" class="block text-xs text-muted-foreground">mín. {{ qtd(i.estoque_minimo) }}</span>
            </p>
          </div>
          <div v-if="nivel(i).pct != null" class="lua-meter">
            <div class="lua-meter-fill" :class="`lua-fill--${nivel(i).tom}`" :style="{ width: `${animar ? nivel(i).pct * 100 : 0}%` }" />
            <div class="lua-meter-marca" :style="{ left: `${nivel(i).marca * 100}%` }" />
          </div>
          <div class="flex flex-wrap items-center gap-1.5">
            <span v-if="!i.ativo" class="lua-badge lua-badge--neutral">Desativado</span>
            <span v-if="i.abaixo_minimo" class="lua-badge lua-badge--danger">{{ i.saldo > 0 ? 'Repor' : 'Sem estoque' }}</span>
            <span v-if="prazo(i)" class="lua-badge" :class="`lua-badge--${prazo(i).tom}`" :title="`Validade ${dataBr(i.data_validade)}`">{{ prazo(i).texto }}</span>
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

      <div class="lua-card hidden overflow-x-auto md:block" :class="{ 'opacity-50': carregandoItens }">
        <header class="lua-card-header">
          <h3 class="lua-section-title">{{ plural(itens.length, 'item', 'itens') }}</h3>
          <span v-if="filtros.alerta !== TODOS" class="lua-badge lua-badge--primary">{{ filtros.alerta === 'REPOR' ? 'Para repor' : 'Alerta de validade' }}</span>
        </header>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Item</TableHead>
              <TableHead class="text-right">Saldo</TableHead>
              <TableHead class="w-44">Nível</TableHead>
              <TableHead>Validade</TableHead>
              <TableHead v-if="ehGestor" class="text-right">Valor</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="i in itens" :key="i.id" :class="{ 'opacity-60': !i.ativo, 'lua-row--danger': i.abaixo_minimo && i.ativo }">
              <TableCell>
                <p class="font-medium">{{ i.nome }}</p>
                <p class="text-xs text-muted-foreground">{{ [i.tipo_label, i.localizacao].filter(Boolean).join(' · ') }}</p>
                <span v-if="!i.ativo" class="lua-badge lua-badge--neutral mt-0.5">Desativado</span>
              </TableCell>
              <TableCell class="text-right whitespace-nowrap tabular-nums">
                <span class="font-semibold" :class="{ 'lua-tom--danger': i.abaixo_minimo }">{{ qtd(i.saldo) }}</span>
                <span class="ml-1 text-xs text-muted-foreground">{{ i.unidade_sigla }}</span>
                <p v-if="i.abaixo_minimo"><span class="lua-badge lua-badge--danger mt-0.5">{{ i.saldo > 0 ? 'Repor' : 'Sem estoque' }}</span></p>
              </TableCell>
              <TableCell>
                <template v-if="nivel(i).pct != null">
                  <div class="lua-meter">
                    <div class="lua-meter-fill" :class="`lua-fill--${nivel(i).tom}`" :style="{ width: `${animar ? nivel(i).pct * 100 : 0}%` }" />
                    <div class="lua-meter-marca" :style="{ left: `${nivel(i).marca * 100}%` }" title="Estoque mínimo" />
                  </div>
                  <p class="mt-1 text-xs text-muted-foreground tabular-nums">mín. {{ qtd(i.estoque_minimo) }} {{ i.unidade_sigla }}</p>
                </template>
                <span v-else class="text-xs text-muted-foreground">Sem mínimo</span>
              </TableCell>
              <TableCell class="whitespace-nowrap">
                <template v-if="prazo(i)">
                  <span class="lua-badge" :class="`lua-badge--${prazo(i).tom}`">{{ prazo(i).texto }}</span>
                  <p class="mt-0.5 text-xs text-muted-foreground tabular-nums">{{ dataBr(i.data_validade) }}</p>
                </template>
                <span v-else class="text-sm text-muted-foreground">—</span>
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
      <ul class="lua-card divide-y md:hidden" :class="{ 'opacity-50': carregandoMov }">
        <li v-for="m in movimentos" :key="m.id" class="space-y-1 px-4 py-3" :class="{ 'opacity-60': m.cancelado }">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-medium" :class="{ 'line-through': m.cancelado }">{{ m.item?.nome }}</p>
              <p class="text-xs text-muted-foreground tabular-nums">{{ dataBr(m.data_movimento) }}<template v-if="m.usuario"> · {{ m.usuario }}</template></p>
            </div>
            <p class="shrink-0 text-right font-semibold tabular-nums" :class="m.efeito_no_saldo < 0 ? 'lua-tom--warning' : 'lua-tom--success'">
              {{ m.efeito_no_saldo > 0 ? '+' : '' }}{{ qtd(m.efeito_no_saldo) }}<span class="ml-1 text-xs font-normal text-muted-foreground">{{ m.item?.unidade_sigla }}</span>
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <span class="lua-badge" :class="ESTILO_MOV[m.tipo]">{{ m.tipo_label }}</span>
            <span v-if="ehGestor && m.valor_total != null" class="text-xs text-muted-foreground tabular-nums">{{ moeda(m.valor_total) }}</span>
            <Button v-if="ehGestor && !m.cancelado" variant="ghost" size="sm" class="ml-auto text-destructive" @click="abrirCancelamento(m)">Cancelar</Button>
          </div>
          <p v-if="m.observacao" class="text-sm text-muted-foreground">{{ m.observacao }}</p>
          <p v-if="m.cancelado" class="text-xs text-destructive">Cancelado: {{ m.motivo_cancelamento }}</p>
        </li>
        <li v-if="!movimentos.length && !carregandoMov" class="px-4 py-3 text-sm text-muted-foreground">Nenhuma movimentação encontrada.</li>
      </ul>

      <div class="lua-card hidden overflow-x-auto md:block" :class="{ 'opacity-50': carregandoMov }">
        <header class="lua-card-header">
          <h3 class="lua-section-title">{{ plural(total, 'movimentação', 'movimentações') }}</h3>
        </header>
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
                <span class="lua-badge" :class="ESTILO_MOV[m.tipo]">{{ m.tipo_label }}</span>
                <p v-if="m.cancelado" class="mt-0.5 text-xs text-destructive">Cancelado: {{ m.motivo_cancelamento }}</p>
              </TableCell>
              <TableCell class="text-right font-semibold whitespace-nowrap tabular-nums" :class="m.efeito_no_saldo < 0 ? 'lua-tom--warning' : 'lua-tom--success'">
                {{ m.efeito_no_saldo > 0 ? '+' : '' }}{{ qtd(m.efeito_no_saldo) }}<span class="ml-1 text-xs font-normal text-muted-foreground">{{ m.item?.unidade_sigla }}</span>
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
    <DialogContent class="lua-estoque lua-dialogo sm:max-w-lg">
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
    <DialogContent class="lua-estoque lua-dialogo sm:max-w-lg">
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
    <DialogContent class="lua-estoque lua-dialogo sm:max-w-md">
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
 </div>
</template>

<style>
/*
 * Identidade visual do Lua (neutros stone, cartões, badges suaves) aplicada à tela de Estoque,
 * mantendo o verde da fazenda como cor primária.
 * Os neutros do shadcn são redefinidos dentro de .lua-estoque; primário e foco seguem os do sistema. Os diálogos recebem a mesma classe.
 */
.lua-estoque {
  --lua-bg: #fafaf9;
  --lua-surface: #ffffff;
  --lua-surface-muted: #f5f5f4;
  --lua-border: #e7e5e4;
  --lua-border-strong: #d6d3d1;
  --lua-divider: #edebe9;
  --lua-text: #1c1917;
  --lua-text-soft: #44403c;
  --lua-text-muted: #78716c;
  /* Primário = verde da fazenda (tokens do sistema), no lugar do violeta do Lua. */
  --lua-primary: var(--primary);
  --lua-primary-soft: color-mix(in oklch, var(--primary) 10%, transparent);
  --lua-primary-border: color-mix(in oklch, var(--primary) 35%, transparent);
  --lua-success: #059669;
  --lua-success-soft: #ecfdf5;
  --lua-danger: #dc2626;
  --lua-danger-soft: #fef2f2;
  --lua-warning: #d97706;
  --lua-warning-soft: #fffbeb;
  --lua-info: #0284c7;
  --lua-info-soft: #f0f9ff;
  --lua-track: #f0eeec;
  --lua-hover: #fafaf9;

  --background: var(--lua-bg);
  --foreground: var(--lua-text);
  --card: var(--lua-surface);
  --card-foreground: var(--lua-text);
  --popover: var(--lua-surface);
  --popover-foreground: var(--lua-text);
  --secondary: var(--lua-surface-muted);
  --secondary-foreground: var(--lua-text-soft);
  --muted: var(--lua-surface-muted);
  --muted-foreground: var(--lua-text-muted);
  --accent: var(--lua-surface-muted);
  --accent-foreground: var(--lua-text);
  --destructive: var(--lua-danger);
  --border: var(--lua-border);
  --input: var(--lua-border-strong);

  background: var(--lua-bg);
  color: var(--lua-text);
  font-feature-settings: 'cv11', 'ss01', 'ss03';
  letter-spacing: -0.005em;
}

.dark .lua-estoque {
  /* Neutros stone escuros (os do Lua puxam para o violeta, que aqui não é usado). */
  --lua-bg: #0c0a09;
  --lua-surface: #1c1917;
  --lua-surface-muted: #262220;
  --lua-border: #2e2a27;
  --lua-border-strong: #44403c;
  --lua-divider: #282422;
  --lua-text: #f5f5f4;
  --lua-text-soft: #d6d3d1;
  --lua-text-muted: #a8a29e;
  --lua-success: #34d399;
  --lua-success-soft: rgba(16, 185, 129, 0.12);
  --lua-danger: #f87171;
  --lua-danger-soft: rgba(220, 38, 38, 0.15);
  --lua-warning: #fbbf24;
  --lua-warning-soft: rgba(217, 119, 6, 0.15);
  --lua-info: #38bdf8;
  --lua-info-soft: rgba(2, 132, 199, 0.15);
  --lua-track: #2e2a27;
  --lua-hover: rgba(255, 255, 255, 0.03);
  --input: #44403c;
}

/* Tipografia */
.lua-estoque .lua-eyebrow {
  font-size: 0.6875rem; font-weight: 500; letter-spacing: 0.08em;
  text-transform: uppercase; color: var(--lua-text-muted);
}
.lua-estoque .lua-page-title {
  font-family: 'Inter Tight', 'Inter', ui-sans-serif, system-ui, sans-serif;
  font-size: 1.375rem; font-weight: 600; letter-spacing: -0.022em; line-height: 1.2;
}
.lua-estoque .lua-page-subtitle { font-size: 0.875rem; color: var(--lua-text-muted); }
.lua-estoque .lua-section-title {
  display: flex; align-items: center; gap: 0.4rem;
  font-family: 'Inter Tight', 'Inter', ui-sans-serif, system-ui, sans-serif;
  font-size: 0.9375rem; font-weight: 600; letter-spacing: -0.01em;
}
.lua-estoque .lua-section-subtitle { font-size: 0.8125rem; color: var(--lua-text-muted); margin-top: 0.1rem; }

/* Cartões */
.lua-estoque .lua-card {
  background: var(--lua-surface);
  border: 1px solid var(--lua-border);
  border-radius: 10px;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.02);
  transition: opacity 0.15s;
}
.lua-estoque .lua-card-header {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem;
  padding: 0.875rem 1.125rem; border-bottom: 1px solid var(--lua-divider);
}
.lua-estoque .lua-card-footer {
  display: block; width: 100%; padding: 0.6rem 1.125rem; text-align: left;
  font-size: 0.8125rem; font-weight: 500; color: var(--lua-primary);
  border-top: 1px solid var(--lua-divider); border-radius: 0 0 10px 10px; cursor: pointer;
}
.lua-estoque .lua-card-footer:hover { background: var(--lua-primary-soft); }

/* KPIs */
.lua-estoque .lua-kpi {
  display: flex; flex-direction: column; gap: 0.35rem; text-align: left;
  background: var(--lua-surface); border: 1px solid var(--lua-border); border-radius: 10px;
  padding: 1rem 1.125rem; transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
}
.lua-estoque .lua-kpi--botao { cursor: pointer; }
.lua-estoque .lua-kpi--botao:hover { border-color: var(--lua-border-strong); transform: translateY(-1px); }
.lua-estoque .lua-kpi--ativo { border-color: var(--lua-primary-border); box-shadow: 0 0 0 3px var(--lua-primary-soft); }
.lua-estoque .lua-kpi.lua-kpi--alerta { border-left: 3px solid var(--lua-danger); }
.lua-estoque .lua-kpi-label {
  display: flex; align-items: center; gap: 0.4rem;
  font-size: 0.8125rem; font-weight: 500; color: var(--lua-text-muted);
}
.lua-estoque .lua-kpi-value {
  font-family: 'Inter Tight', 'Inter', ui-sans-serif, system-ui, sans-serif;
  font-size: 1.5rem; font-weight: 600; letter-spacing: -0.02em; line-height: 1.1;
}
.lua-estoque .lua-kpi-sub { font-size: 0.75rem; color: var(--lua-text-muted); }

/* Cores semânticas */
.lua-estoque .lua-tom--primary { color: var(--lua-primary); }
.lua-estoque .lua-tom--success { color: var(--lua-success); }
.lua-estoque .lua-tom--danger { color: var(--lua-danger); }
.lua-estoque .lua-tom--warning { color: var(--lua-warning); }

/* Badges suaves */
.lua-estoque .lua-badge {
  display: inline-flex; align-items: center; gap: 0.25rem; width: fit-content; white-space: nowrap;
  font-size: 0.75rem; font-weight: 500; line-height: 1.2; letter-spacing: 0.005em;
  padding: 0.22em 0.6em; border-radius: 6px;
}
.lua-estoque .lua-badge--success { background: var(--lua-success-soft); color: var(--lua-success); }
.lua-estoque .lua-badge--danger { background: var(--lua-danger-soft); color: var(--lua-danger); }
.lua-estoque .lua-badge--warning { background: var(--lua-warning-soft); color: var(--lua-warning); }
.lua-estoque .lua-badge--info { background: var(--lua-info-soft); color: var(--lua-info); }
.lua-estoque .lua-badge--primary { background: var(--lua-primary-soft); color: var(--lua-primary); }
.lua-estoque .lua-badge--neutral { background: var(--lua-surface-muted); color: var(--lua-text-soft); border: 1px solid var(--lua-border); }

/* Barras de nível / prazo */
.lua-estoque .lua-meter {
  position: relative; height: 6px; border-radius: 999px; background: var(--lua-track); overflow: visible;
}
.lua-estoque .lua-meter-fill {
  height: 100%; border-radius: 999px; transition: width 0.7s cubic-bezier(0.22, 1, 0.36, 1), background-color 0.3s;
}
.lua-estoque .lua-fill--success { background: var(--lua-success); }
.lua-estoque .lua-fill--warning { background: var(--lua-warning); }
.lua-estoque .lua-fill--danger { background: var(--lua-danger); }
.lua-estoque .lua-meter-marca {
  position: absolute; top: -3px; width: 2px; height: 12px; margin-left: -1px;
  border-radius: 1px; background: var(--lua-text-soft); opacity: 0.55;
}

/* Listas dos painéis */
.lua-estoque .lua-lista > * + * { border-top: 1px solid var(--lua-divider); }
.lua-estoque .lua-linha { display: flex; gap: 0.75rem; padding: 0.7rem 1.125rem; }
.lua-estoque .lua-linha:hover { background: var(--lua-hover); }
.lua-lista-move, .lua-lista-enter-active, .lua-lista-leave-active { transition: all 0.35s ease; }
.lua-lista-enter-from, .lua-lista-leave-to { opacity: 0; transform: translateY(6px); }
.lua-lista-leave-active { position: absolute; }

/* Tabelas no padrão do Lua: cabeçalho discreto em caixa alta, linhas com divisória leve */
.lua-estoque [data-slot='table-head'] {
  font-size: 0.75rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em;
  color: var(--lua-text-muted); padding-inline: 0.875rem;
}
.lua-estoque [data-slot='table-cell'] { padding: 0.7rem 0.875rem; }
.lua-estoque [data-slot='table-row'] { border-color: var(--lua-divider); }
.lua-estoque [data-slot='table-body'] [data-slot='table-row']:hover { background: var(--lua-hover); }
.lua-estoque .lua-row--danger > td:first-child { box-shadow: inset 3px 0 0 var(--lua-danger); }

/* Diálogos: superfície branca como os modais do Lua */
.lua-dialogo.lua-estoque { background: var(--lua-surface); border-color: var(--lua-border); border-radius: 14px; }

@media (prefers-reduced-motion: reduce) {
  .lua-estoque .lua-meter-fill, .lua-estoque .lua-kpi, .lua-lista-move, .lua-lista-enter-active, .lua-lista-leave-active { transition: none; }
}
</style>
