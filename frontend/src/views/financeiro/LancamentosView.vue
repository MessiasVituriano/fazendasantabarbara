<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { watchDebounced } from '@vueuse/core'
import { ChevronLeft, ChevronRight, Download, Paperclip, Plus, Search, SlidersHorizontal } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import http from '../../api/http'
import AlertaErros from '../../components/AlertaErros.vue'
import DetalheLancamento from '../../components/financeiro/DetalheLancamento.vue'
import FormLancamento from '../../components/financeiro/FormLancamento.vue'
import SeloSituacao from '../../components/financeiro/SeloSituacao.vue'
import ValorTipo from '../../components/financeiro/ValorTipo.vue'
import CabecalhoPagina from '../../components/layout/CabecalhoPagina.vue'
import { useCadastrosFinanceiros } from '../../composables/useCadastrosFinanceiros'
import { dataBr, fimDoMes, hoje, inicioDoMes, moeda } from '../../formatos'

/** Todos os lançamentos, com filtros que ficam na URL (dá para salvar/compartilhar a busca). */
const route = useRoute()
const router = useRouter()
const { categorias, lotes } = useCadastrosFinanceiros()
const TODOS = '_'

const q = route.query
const filtros = ref({
  tipo: q.tipo ?? TODOS,
  situacao: q.situacao ? [].concat(q.situacao) : [],
  base: q.base ?? 'competencia',
  // Vindo de um lote (ou de um link com filtro), mostra o histórico inteiro por padrão.
  periodo: q.inicio || q.fim ? 'custom' : (q.periodo ?? (q.lote_id ? 'tudo' : 'mes')),
  inicio: q.inicio ?? inicioDoMes(),
  fim: q.fim ?? fimDoMes(),
  categoria_id: q.categoria_id ?? TODOS,
  lote_id: q.lote_id ?? TODOS,
  busca: q.busca ?? '',
})
const pagina = ref(1)

const PERIODOS = [
  { v: 'mes', r: 'Este mês' }, { v: 'mes_anterior', r: 'Mês anterior' }, { v: 'ano', r: 'Este ano' },
  { v: 'tudo', r: 'Tudo' }, { v: 'custom', r: 'Personalizado' },
]
const intervalo = computed(() => {
  const f = filtros.value
  switch (f.periodo) {
    case 'mes': return { inicio: inicioDoMes(), fim: fimDoMes() }
    case 'mes_anterior': return { inicio: inicioDoMes(hoje(), -1), fim: fimDoMes(hoje(), -1) }
    case 'ano': return { inicio: `${hoje().slice(0, 4)}-01-01`, fim: `${hoje().slice(0, 4)}-12-31` }
    case 'tudo': return {}
    default: return { inicio: f.inicio || undefined, fim: f.fim || undefined }
  }
})

const params = computed(() => {
  const f = filtros.value
  return {
    tipo: f.tipo === TODOS ? undefined : f.tipo,
    situacao: f.situacao.length ? f.situacao : undefined,
    base: f.base,
    ...intervalo.value,
    categoria_id: f.categoria_id === TODOS ? undefined : [f.categoria_id],
    lote_id: f.lote_id === TODOS ? undefined : f.lote_id,
    busca: f.busca.trim() || undefined,
  }
})

const lista = ref([])
const meta = ref(null)
const carregando = ref(false)
const erros = ref([])
async function carregar() {
  carregando.value = true
  try {
    const { data } = await http.get('/financeiro/lancamentos', { params: { ...params.value, page: pagina.value, por_pagina: 50 } })
    lista.value = data.data
    meta.value = data.meta
    erros.value = []
  } catch (e) { erros.value = e.mensagens } finally { carregando.value = false }
}

// Filtros → URL e recarga (busca com atraso para não consultar a cada tecla).
function sincronizar() {
  const f = filtros.value
  const query = {
    tipo: f.tipo === TODOS ? undefined : f.tipo, situacao: f.situacao.length ? f.situacao : undefined,
    base: f.base === 'competencia' ? undefined : f.base,
    periodo: f.periodo === 'custom' || (f.periodo === 'mes' && f.lote_id === TODOS) ? undefined : f.periodo,
    inicio: f.periodo === 'custom' ? f.inicio : undefined, fim: f.periodo === 'custom' ? f.fim : undefined,
    categoria_id: f.categoria_id === TODOS ? undefined : f.categoria_id,
    lote_id: f.lote_id === TODOS ? undefined : f.lote_id, busca: f.busca.trim() || undefined,
  }
  router.replace({ query })
  pagina.value = 1
  carregar()
}
watch(() => [filtros.value.tipo, filtros.value.situacao, filtros.value.base, filtros.value.periodo, filtros.value.inicio,
  filtros.value.fim, filtros.value.categoria_id, filtros.value.lote_id], sincronizar, { deep: true })
watchDebounced(() => filtros.value.busca, sincronizar, { debounce: 350 })
watch(pagina, carregar)
carregar()

const categoriasFiltro = computed(() => categorias.value.filter((c) => filtros.value.tipo === TODOS || c.tipo === filtros.value.tipo))
const rotuloData = computed(() => ({ pagamento: 'Pagamento', vencimento: 'Vencimento' })[filtros.value.base] ?? 'Competência')

/** No celular os filtros ficam recolhidos; a busca continua sempre à vista. */
const filtrosAbertos = ref(false)
const filtrosAtivos = computed(() => {
  const f = filtros.value
  return [f.tipo !== TODOS, f.situacao.length > 0, f.base !== 'competencia', f.periodo !== 'mes', f.categoria_id !== TODOS, f.lote_id !== TODOS].filter(Boolean).length
})

const totais = computed(() => meta.value?.totais)
const ultimaPagina = computed(() => meta.value?.last_page ?? 1)

const novoAberto = ref(false)
const detalheId = ref(null)
const padraoNovo = computed(() => ({
  ...(filtros.value.tipo !== TODOS ? { tipo: filtros.value.tipo } : {}),
  ...(filtros.value.lote_id !== TODOS && filtros.value.lote_id !== 'sem' ? { lote_id: filtros.value.lote_id } : {}),
}))

/** CSV com o filtro inteiro (até 5.000 linhas), separador ";" e decimais com vírgula para abrir direto no Excel. */
const exportando = ref(false)
async function exportar() {
  exportando.value = true
  try {
    const linhas = []
    for (let p = 1; p <= 10; p++) {
      const { data } = await http.get('/financeiro/lancamentos', { params: { ...params.value, page: p, por_pagina: 500 } })
      linhas.push(...data.data)
      if (p >= data.meta.last_page) break
    }
    const num = (v) => (v == null ? '' : String(v).replace('.', ','))
    const cel = (v) => `"${String(v ?? '').replaceAll('"', '""')}"`
    const cab = ['Tipo', 'Descrição', 'Categoria', 'Grupo de custo', 'Valor', 'Competência', 'Vencimento', 'Situação', 'Pagamento',
      'Valor pago', 'Conta', 'Forma', 'Fornecedor/Cliente', 'Lote', 'Documento', 'Observações']
    const csv = [cab.map(cel).join(';'), ...linhas.map((l) => [
      l.tipo_label, l.descricao, l.categoria, l.grupo_custo_label, num(l.valor), dataBr(l.data_competencia), dataBr(l.data_vencimento),
      l.situacao_label, l.data_pagamento ? dataBr(l.data_pagamento) : '', num(l.valor_pago), l.conta, l.forma_pagamento_label,
      l.pessoa, l.lote, l.documento, l.observacoes,
    ].map(cel).join(';'))].join('\r\n')
    const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }))
    Object.assign(document.createElement('a'), { href: url, download: `lancamentos-${hoje()}.csv` }).click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
    if (linhas.length >= 5000) toast.warning('Exportadas as primeiras 5.000 linhas. Refine o filtro para exportar o restante.')
  } catch (e) { toast.error(e.mensagens?.[0] ?? 'Falha ao exportar') } finally { exportando.value = false }
}
</script>

<template>
  <CabecalhoPagina titulo="Lançamentos" :contador="totais?.quantidade ?? null">
    <template #acoes>
      <Button variant="outline" :disabled="exportando" @click="exportar"><Download /> <span class="hidden sm:inline">Exportar</span></Button>
      <Button @click="novoAberto = true"><Plus /> Novo lançamento</Button>
    </template>
    <template #filtros>
      <div class="flex w-full items-center gap-2 sm:contents">
        <div class="relative min-w-0 flex-1 sm:order-last sm:w-56 sm:flex-none">
          <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input v-model="filtros.busca" placeholder="Descrição, documento, pessoa" class="h-8 pl-8" />
        </div>
        <Button variant="outline" class="sm:hidden" :aria-expanded="filtrosAbertos" @click="filtrosAbertos = !filtrosAbertos">
          <SlidersHorizontal /> Filtros
          <Badge v-if="filtrosAtivos" variant="secondary" class="tabular-nums">{{ filtrosAtivos }}</Badge>
        </Button>
      </div>
      <div class="w-full flex-wrap items-center gap-2 sm:contents" :class="filtrosAbertos ? 'flex' : 'hidden'">
      <ToggleGroup v-model="filtros.tipo" type="single" variant="outline" size="sm" aria-label="Tipo" @update:model-value="(v) => !v && (filtros.tipo = TODOS)">
        <ToggleGroupItem :value="TODOS" class="px-3">Todos</ToggleGroupItem>
        <ToggleGroupItem value="RECEITA" class="px-3">Receitas</ToggleGroupItem>
        <ToggleGroupItem value="DESPESA" class="px-3">Despesas</ToggleGroupItem>
      </ToggleGroup>
      <ToggleGroup v-model="filtros.situacao" type="multiple" variant="outline" size="sm" aria-label="Situação">
        <ToggleGroupItem value="PENDENTE" class="px-3">A vencer</ToggleGroupItem>
        <ToggleGroupItem value="VENCIDO" class="px-3">Vencidos</ToggleGroupItem>
        <ToggleGroupItem value="PAGO" class="px-3">Pagos</ToggleGroupItem>
        <ToggleGroupItem value="CANCELADO" class="px-3">Cancelados</ToggleGroupItem>
      </ToggleGroup>
      <Select v-model="filtros.base">
        <SelectTrigger size="sm" class="w-44" aria-label="Data usada no período"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem value="competencia">Por competência</SelectItem>
          <SelectItem value="vencimento">Por vencimento</SelectItem>
          <SelectItem value="pagamento">Por pagamento</SelectItem>
        </SelectContent>
      </Select>
      <Select v-model="filtros.periodo">
        <SelectTrigger size="sm" class="w-36" aria-label="Período"><SelectValue /></SelectTrigger>
        <SelectContent><SelectItem v-for="p in PERIODOS" :key="p.v" :value="p.v">{{ p.r }}</SelectItem></SelectContent>
      </Select>
      <template v-if="filtros.periodo === 'custom'">
        <Input v-model="filtros.inicio" type="date" class="h-8 w-38" aria-label="Data inicial" />
        <span class="text-sm text-muted-foreground">a</span>
        <Input v-model="filtros.fim" type="date" :min="filtros.inicio" class="h-8 w-38" aria-label="Data final" />
      </template>
      <Select v-model="filtros.categoria_id">
        <SelectTrigger size="sm" class="w-48" aria-label="Categoria"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem :value="TODOS">Todas as categorias</SelectItem>
          <SelectItem v-for="c in categoriasFiltro" :key="c.id" :value="String(c.id)">{{ c.nome }}</SelectItem>
        </SelectContent>
      </Select>
      <Select v-model="filtros.lote_id">
        <SelectTrigger size="sm" class="w-40" aria-label="Lote"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem :value="TODOS">Todos os lotes</SelectItem>
          <SelectItem value="sem">Sem lote (geral)</SelectItem>
          <SelectItem v-for="l in lotes" :key="l.id" :value="String(l.id)">{{ l.nome }}</SelectItem>
        </SelectContent>
      </Select>
      </div>
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erros" />

    <div v-if="totais" class="grid gap-3 sm:grid-cols-3">
      <Card class="py-4"><CardContent class="px-4">
        <p class="text-xs text-muted-foreground">Receitas no filtro</p>
        <p class="text-xl font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">{{ moeda(totais.receitas) }}</p>
      </CardContent></Card>
      <Card class="py-4"><CardContent class="px-4">
        <p class="text-xs text-muted-foreground">Despesas no filtro</p>
        <p class="text-xl font-semibold tabular-nums text-red-700 dark:text-red-400">{{ moeda(totais.despesas) }}</p>
      </CardContent></Card>
      <Card class="py-4"><CardContent class="px-4">
        <p class="text-xs text-muted-foreground">Diferença (receitas − despesas)</p>
        <p class="text-xl font-semibold tabular-nums" :class="totais.saldo < 0 ? 'text-red-700 dark:text-red-400' : ''">{{ moeda(totais.saldo) }}</p>
      </CardContent></Card>
    </div>
    <p class="text-xs text-muted-foreground">
      Totais somam pagos e em aberto pelo valor original{{ filtros.situacao.includes('CANCELADO') ? '' : '; cancelados ficam de fora' }}.
      O saldo em conta (só o que foi pago) está na Visão geral e no Fluxo de caixa.
    </p>

    <!-- Celular: lista em cartões, com o valor sempre visível -->
    <Card class="gap-0 divide-y py-0 sm:hidden">
      <button v-for="l in lista" :key="l.id" class="flex w-full flex-col gap-1 px-4 py-3 text-left hover:bg-accent/50" :class="l.situacao === 'CANCELADO' ? 'opacity-60' : ''" @click="detalheId = l.id">
        <span class="flex w-full items-start justify-between gap-3">
          <span class="min-w-0 truncate font-medium">{{ l.descricao }} <Paperclip v-if="l.anexos_count" class="inline size-3.5 text-muted-foreground" /></span>
          <ValorTipo :valor="l.valor" :tipo="l.tipo" class="shrink-0 font-semibold" />
        </span>
        <span class="block w-full truncate text-xs text-muted-foreground">
          {{ l.pessoa ?? l.categoria }}<template v-if="filtros.base !== 'vencimento'"> · vence {{ dataBr(l.data_vencimento) }}</template>
        </span>
        <span class="flex w-full items-center justify-between gap-3">
          <SeloSituacao :situacao="l.situacao" :tipo="l.tipo" />
          <span class="text-xs tabular-nums text-muted-foreground">{{ rotuloData }} {{ dataBr(l['data_' + filtros.base]) }}</span>
        </span>
      </button>
      <p v-if="!lista.length && !carregando" class="py-10 text-center text-sm text-muted-foreground">Nenhum lançamento encontrado com esses filtros.</p>
    </Card>

    <Card class="hidden py-0 sm:flex">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead class="w-28">{{ rotuloData }}</TableHead>
            <TableHead>Descrição</TableHead>
            <TableHead class="hidden xl:table-cell">Categoria</TableHead>
            <TableHead class="hidden xl:table-cell">Lote</TableHead>
            <TableHead class="hidden sm:table-cell">Situação</TableHead>
            <TableHead class="text-right">Valor</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="l in lista" :key="l.id" class="cursor-pointer" :class="l.situacao === 'CANCELADO' ? 'opacity-60' : ''" tabindex="0"
            @click="detalheId = l.id" @keydown.enter="detalheId = l.id">
            <TableCell class="tabular-nums">{{ dataBr(l['data_' + filtros.base]) }}</TableCell>
            <TableCell class="max-w-72">
              <p class="truncate font-medium">{{ l.descricao }} <Paperclip v-if="l.anexos_count" class="inline size-3.5 text-muted-foreground" /></p>
              <p class="truncate text-xs text-muted-foreground">
                {{ l.pessoa ?? '' }}<template v-if="l.pessoa && filtros.base !== 'vencimento'"> · </template>
                <template v-if="filtros.base !== 'vencimento'">vence {{ dataBr(l.data_vencimento) }}</template>
                <span class="xl:hidden"> · {{ l.categoria }}</span>
              </p>
            </TableCell>
            <TableCell class="hidden xl:table-cell">{{ l.categoria }}</TableCell>
            <TableCell class="hidden xl:table-cell">{{ l.lote ?? '—' }}</TableCell>
            <TableCell class="hidden sm:table-cell"><SeloSituacao :situacao="l.situacao" :tipo="l.tipo" /></TableCell>
            <TableCell class="text-right"><ValorTipo :valor="l.valor" :tipo="l.tipo" /></TableCell>
          </TableRow>
          <TableRow v-if="!lista.length && !carregando">
            <TableCell colspan="6" class="py-10 text-center text-muted-foreground">Nenhum lançamento encontrado com esses filtros.</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </Card>

    <div v-if="ultimaPagina > 1" class="flex items-center justify-end gap-2 text-sm">
      <Button variant="outline" size="sm" :disabled="pagina <= 1" @click="pagina--"><ChevronLeft /> Anterior</Button>
      <span class="tabular-nums text-muted-foreground">{{ pagina }} de {{ ultimaPagina }}</span>
      <Button variant="outline" size="sm" :disabled="pagina >= ultimaPagina" @click="pagina++">Próxima <ChevronRight /></Button>
    </div>
  </main>

  <FormLancamento v-model:open="novoAberto" :padrao="padraoNovo" @salvo="carregar" />
  <DetalheLancamento :id="detalheId" @fechar="detalheId = null" @alterado="carregar" />
</template>
