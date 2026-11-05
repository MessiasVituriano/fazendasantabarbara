<script setup>
import { computed, ref, watch } from 'vue'
import { FileDown, Info } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import http from '../../api/http'
import AlertaErros from '../../components/AlertaErros.vue'
import GraficoReceitasDespesas from '../../components/financeiro/GraficoReceitasDespesas.vue'
import CabecalhoPagina from '../../components/layout/CabecalhoPagina.vue'
import { useCadastrosFinanceiros } from '../../composables/useCadastrosFinanceiros'
import { dataBr, fimDoMes, hoje, inicioDoMes, mesCurto, moeda, numero } from '../../formatos'

/**
 * Fluxo de caixa: realizado (pagamentos) por mês ou dia, com a previsão das contas em aberto ao lado,
 * sem misturar as duas coisas. Comparativo com o período anterior de mesmo tamanho.
 */
const { contas } = useCadastrosFinanceiros()
const TODAS = 'todas'

const PRESETS = {
  ano: () => ({ inicio: `${hoje().slice(0, 4)}-01-01`, fim: `${hoje().slice(0, 4)}-12-31` }),
  doze: () => ({ inicio: inicioDoMes(hoje(), -11), fim: fimDoMes() }),
  seis: () => ({ inicio: inicioDoMes(), fim: fimDoMes(hoje(), 5) }),
}
const preset = ref('ano')
const periodo = ref(PRESETS.ano())
const conta = ref(TODAS)
const agrupamento = ref('mes')

watch(preset, (p) => {
  if (!p) return (preset.value = 'custom')
  if (PRESETS[p]) periodo.value = PRESETS[p]()
})

const dados = ref(null)
const erros = ref([])
const carregando = ref(false)
async function carregar() {
  const { inicio, fim } = periodo.value
  if (!inicio || !fim || inicio > fim) return
  carregando.value = true
  try {
    dados.value = (await http.get('/financeiro/fluxo-caixa', {
      params: { inicio, fim, agrupamento: agrupamento.value, conta_financeira_id: conta.value === TODAS ? undefined : conta.value },
    })).data.data
    erros.value = []
  } catch (e) {
    erros.value = e.mensagens
  } finally {
    carregando.value = false
  }
}
watch([periodo, conta, agrupamento], carregar, { immediate: true, deep: true })

const rotulo = (p) => (p.length > 7 ? dataBr(p) : mesCurto(p))
const temAbertura = computed(() => dados.value?.totais.abertura_contas !== 0)
const temCapital = computed(() => !!dados.value && (dados.value.totais.aportes !== 0 || dados.value.totais.retiradas !== 0))
const colunasRealizado = computed(() => 5 + (temAbertura.value ? 1 : 0) + (temCapital.value ? 2 : 0))
const temPrevisao = computed(() => !!dados.value && !dados.value.conta_id)
const vencidosPrevistos = computed(() => {
  const v = dados.value?.previsto_inclui_vencidos
  return v && (v.receitas > 0 || v.despesas > 0) ? v : null
})
const corValor = (v) => (v < 0 ? 'text-red-700 dark:text-red-400' : '')
const variacao = (v) => (v == null ? 'sem base' : `${v > 0 ? '+' : ''}${numero(v, 1)}%`)
const maior = (lista) => Math.max(1, ...lista.map((c) => c.total))

function exportarCsv() {
  const d = dados.value
  const n = (v) => numero(v, 2).replace(/\./g, '')
  const cab = ['Período', 'Saldo inicial', ...(temAbertura.value ? ['Abertura de contas'] : []), ...(temCapital.value ? ['Aportes', 'Retiradas'] : []), 'Entradas', 'Saídas', 'Resultado', 'Saldo final',
    ...(temPrevisao.value ? ['Entradas previstas', 'Saídas previstas', 'Saldo projetado'] : [])]
  const linhas = d.periodos.map((p) => [rotulo(p.periodo), n(p.saldo_inicial), ...(temAbertura.value ? [n(p.abertura_contas)] : []), ...(temCapital.value ? [n(p.aportes), n(p.retiradas)] : []), n(p.entradas), n(p.saidas),
    n(p.resultado), n(p.saldo_final), ...(temPrevisao.value ? [n(p.entradas_previstas), n(p.saidas_previstas), n(p.saldo_projetado)] : [])])
  const csv = '﻿' + [cab, ...linhas].map((l) => l.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(';')).join('\r\n')
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  const link = Object.assign(document.createElement('a'), { href: url, download: `fluxo-de-caixa_${d.inicio}_${d.fim}.csv` })
  link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
</script>

<template>
  <CabecalhoPagina titulo="Fluxo de caixa">
    <template #acoes>
      <Button variant="outline" :disabled="!dados?.periodos.length" @click="exportarCsv"><FileDown /> Exportar CSV</Button>
    </template>
    <template #filtros>
      <ToggleGroup v-model="preset" type="single" variant="outline" size="sm" aria-label="Período">
        <ToggleGroupItem v-for="p in [['ano', 'Este ano'], ['doze', 'Últimos 12 meses'], ['seis', 'Próximos 6 meses'], ['custom', 'Personalizado']]" :key="p[0]" :value="p[0]"
          class="px-3 data-[state=on]:bg-primary data-[state=on]:text-primary-foreground data-[state=on]:hover:bg-primary/90">{{ p[1] }}</ToggleGroupItem>
      </ToggleGroup>
      <template v-if="preset === 'custom'">
        <Input v-model="periodo.inicio" type="date" class="h-8 w-38" aria-label="Data inicial" :aria-invalid="periodo.inicio > periodo.fim" />
        <span class="text-sm text-muted-foreground">a</span>
        <Input v-model="periodo.fim" type="date" :min="periodo.inicio" class="h-8 w-38" aria-label="Data final" :aria-invalid="periodo.inicio > periodo.fim" />
      </template>
      <Select v-model="conta">
        <SelectTrigger size="sm" class="w-44" aria-label="Conta"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem :value="TODAS">Todas as contas</SelectItem>
          <SelectItem v-for="c in contas" :key="c.id" :value="String(c.id)">{{ c.nome }}</SelectItem>
        </SelectContent>
      </Select>
      <ToggleGroup v-model="agrupamento" type="single" variant="outline" size="sm" aria-label="Agrupar por" @update:model-value="(v) => !v && (agrupamento = 'mes')">
        <ToggleGroupItem value="mes" class="px-3 data-[state=on]:bg-primary data-[state=on]:text-primary-foreground">Por mês</ToggleGroupItem>
        <ToggleGroupItem value="dia" class="px-3 data-[state=on]:bg-primary data-[state=on]:text-primary-foreground">Por dia</ToggleGroupItem>
      </ToggleGroup>
    </template>
  </CabecalhoPagina>

  <main class="space-y-6 px-4 py-6 md:px-6" :class="{ 'opacity-60': carregando }">
    <AlertaErros :mensagens="erros" />

    <template v-if="dados">
      <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <Card>
          <CardHeader><CardDescription>Saldo inicial · {{ dataBr(dados.inicio) }}</CardDescription>
            <CardTitle class="text-xl tabular-nums" :class="corValor(dados.saldo_inicial)">{{ moeda(dados.saldo_inicial) }}</CardTitle></CardHeader>
        </Card>
        <Card>
          <CardHeader><CardDescription>Entradas (realizado)</CardDescription>
            <CardTitle class="text-xl tabular-nums text-emerald-700 dark:text-emerald-400">{{ moeda(dados.totais.entradas) }}</CardTitle></CardHeader>
          <CardContent class="text-xs text-muted-foreground">{{ variacao(dados.comparativo.variacao_entradas) }} vs. período anterior</CardContent>
        </Card>
        <Card>
          <CardHeader><CardDescription>Saídas (realizado)</CardDescription>
            <CardTitle class="text-xl tabular-nums text-red-700 dark:text-red-400">{{ moeda(dados.totais.saidas) }}</CardTitle></CardHeader>
          <CardContent class="text-xs text-muted-foreground">{{ variacao(dados.comparativo.variacao_saidas) }} vs. período anterior</CardContent>
        </Card>
        <Card>
          <CardHeader><CardDescription>Saldo final (realizado)</CardDescription>
            <CardTitle class="text-xl tabular-nums" :class="corValor(dados.saldo_final)">{{ moeda(dados.saldo_final) }}</CardTitle></CardHeader>
          <CardContent v-if="temAbertura || temCapital" class="space-y-0.5 text-xs text-muted-foreground">
            <p v-if="temAbertura">Inclui {{ moeda(dados.totais.abertura_contas) }} de contas abertas no período.</p>
            <p v-if="temCapital">Inclui {{ moeda(dados.totais.aportes) }} de aportes e {{ moeda(dados.totais.retiradas) }} de retiradas.</p>
          </CardContent>
        </Card>
        <Card class="border-dashed">
          <CardHeader><CardDescription>Saldo projetado (previsto) · {{ dataBr(dados.fim) }}</CardDescription>
            <CardTitle class="text-xl italic tabular-nums" :class="corValor(dados.saldo_projetado)">{{ temPrevisao ? moeda(dados.saldo_projetado) : '—' }}</CardTitle></CardHeader>
          <CardContent class="text-xs text-muted-foreground">
            <template v-if="temPrevisao">Saldo realizado + contas em aberto que vencem até o fim do período.</template>
            <template v-else>A previsão não aparece no filtro por conta: contas em aberto ainda não têm conta definida.</template>
          </CardContent>
        </Card>
      </section>

      <p v-if="temPrevisao && vencidosPrevistos" class="flex items-start gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
        <Info class="mt-0.5 size-4 shrink-0" />
        A previsão inclui contas vencidas e não pagas, consideradas para hoje: {{ moeda(vencidosPrevistos.despesas) }} a pagar e {{ moeda(vencidosPrevistos.receitas) }} a receber.
      </p>

      <section class="grid gap-4 lg:grid-cols-3">
        <Card class="lg:col-span-2">
          <CardHeader>
            <CardTitle class="text-base">Entradas × saídas</CardTitle>
            <CardDescription>Realizado por {{ agrupamento === 'dia' ? 'dia' : 'mês' }}, com o saldo ao final de cada período.</CardDescription>
          </CardHeader>
          <CardContent>
            <GraficoReceitasDespesas :serie="dados.periodos" chave-periodo="periodo" chave-a="entradas" rotulo-a="Entradas" chave-b="saidas" rotulo-b="Saídas"
              linha="saldo_final" rotulo-linha="Saldo final" descricao="Entradas, saídas e saldo do fluxo de caixa" />
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Comparativo</CardTitle>
            <CardDescription>Período anterior: {{ dataBr(dados.comparativo.inicio) }} a {{ dataBr(dados.comparativo.fim) }}</CardDescription>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader><TableRow><TableHead /><TableHead class="text-right">Anterior</TableHead><TableHead class="text-right">Atual</TableHead></TableRow></TableHeader>
              <TableBody>
                <TableRow><TableCell>Entradas</TableCell><TableCell class="text-right tabular-nums">{{ moeda(dados.comparativo.entradas) }}</TableCell><TableCell class="text-right tabular-nums">{{ moeda(dados.totais.entradas) }}</TableCell></TableRow>
                <TableRow><TableCell>Saídas</TableCell><TableCell class="text-right tabular-nums">{{ moeda(dados.comparativo.saidas) }}</TableCell><TableCell class="text-right tabular-nums">{{ moeda(dados.totais.saidas) }}</TableCell></TableRow>
                <TableRow class="font-medium"><TableCell>Resultado</TableCell>
                  <TableCell class="text-right tabular-nums" :class="corValor(dados.comparativo.resultado)">{{ moeda(dados.comparativo.resultado) }}</TableCell>
                  <TableCell class="text-right tabular-nums" :class="corValor(dados.totais.resultado)">{{ moeda(dados.totais.resultado) }}</TableCell></TableRow>
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </section>

      <Card>
        <CardHeader>
          <CardTitle class="text-base">Detalhamento</CardTitle>
          <CardDescription>
            Realizado = pagamentos efetivados. Previsto = contas em aberto pela data de vencimento (nunca entram no saldo).
            <template v-if="temCapital">Aportes e retiradas mudam o saldo, mas não entram em entradas, saídas nem resultado.</template>
          </CardDescription>
        </CardHeader>
        <CardContent class="overflow-x-auto">
          <Table class="min-w-[760px]">
            <TableHeader>
              <TableRow>
                <TableHead rowspan="2">Período</TableHead>
                <TableHead :colspan="colunasRealizado" class="text-center">Realizado</TableHead>
                <TableHead v-if="temPrevisao" colspan="3" class="border-l text-center italic">Previsto</TableHead>
              </TableRow>
              <TableRow>
                <TableHead class="text-right">Saldo inicial</TableHead>
                <TableHead v-if="temAbertura" class="text-right">Abertura de contas</TableHead>
                <template v-if="temCapital">
                  <TableHead class="text-right">Aportes</TableHead>
                  <TableHead class="text-right">Retiradas</TableHead>
                </template>
                <TableHead class="text-right">Entradas</TableHead>
                <TableHead class="text-right">Saídas</TableHead>
                <TableHead class="text-right">Resultado</TableHead>
                <TableHead class="text-right">Saldo final</TableHead>
                <template v-if="temPrevisao">
                  <TableHead class="border-l text-right italic">Entradas</TableHead>
                  <TableHead class="text-right italic">Saídas</TableHead>
                  <TableHead class="text-right italic">Saldo projetado</TableHead>
                </template>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="p in dados.periodos" :key="p.periodo" class="tabular-nums">
                <TableCell class="font-medium">{{ rotulo(p.periodo) }}</TableCell>
                <TableCell class="text-right" :class="corValor(p.saldo_inicial)">{{ moeda(p.saldo_inicial) }}</TableCell>
                <TableCell v-if="temAbertura" class="text-right">{{ p.abertura_contas ? moeda(p.abertura_contas) : '—' }}</TableCell>
                <template v-if="temCapital">
                  <TableCell class="text-right">{{ p.aportes ? moeda(p.aportes) : '—' }}</TableCell>
                  <TableCell class="text-right">{{ p.retiradas ? moeda(p.retiradas) : '—' }}</TableCell>
                </template>
                <TableCell class="text-right text-emerald-700 dark:text-emerald-400">{{ p.entradas ? moeda(p.entradas) : '—' }}</TableCell>
                <TableCell class="text-right text-red-700 dark:text-red-400">{{ p.saidas ? moeda(p.saidas) : '—' }}</TableCell>
                <TableCell class="text-right" :class="corValor(p.resultado)">{{ moeda(p.resultado) }}</TableCell>
                <TableCell class="text-right font-medium" :class="corValor(p.saldo_final)">{{ moeda(p.saldo_final) }}</TableCell>
                <template v-if="temPrevisao">
                  <TableCell class="border-l text-right italic text-muted-foreground">{{ p.entradas_previstas ? moeda(p.entradas_previstas) : '—' }}</TableCell>
                  <TableCell class="text-right italic text-muted-foreground">{{ p.saidas_previstas ? moeda(p.saidas_previstas) : '—' }}</TableCell>
                  <TableCell class="text-right italic" :class="p.saldo_projetado < 0 ? 'text-red-700 dark:text-red-400' : 'text-muted-foreground'">{{ moeda(p.saldo_projetado) }}</TableCell>
                </template>
              </TableRow>
            </TableBody>
            <TableFooter>
              <TableRow class="tabular-nums">
                <TableCell>Total</TableCell>
                <TableCell class="text-right">{{ moeda(dados.saldo_inicial) }}</TableCell>
                <TableCell v-if="temAbertura" class="text-right">{{ moeda(dados.totais.abertura_contas) }}</TableCell>
                <template v-if="temCapital">
                  <TableCell class="text-right">{{ moeda(dados.totais.aportes) }}</TableCell>
                  <TableCell class="text-right">{{ moeda(dados.totais.retiradas) }}</TableCell>
                </template>
                <TableCell class="text-right">{{ moeda(dados.totais.entradas) }}</TableCell>
                <TableCell class="text-right">{{ moeda(dados.totais.saidas) }}</TableCell>
                <TableCell class="text-right" :class="corValor(dados.totais.resultado)">{{ moeda(dados.totais.resultado) }}</TableCell>
                <TableCell class="text-right" :class="corValor(dados.saldo_final)">{{ moeda(dados.saldo_final) }}</TableCell>
                <template v-if="temPrevisao">
                  <TableCell class="border-l text-right italic">{{ moeda(dados.totais.entradas_previstas) }}</TableCell>
                  <TableCell class="text-right italic">{{ moeda(dados.totais.saidas_previstas) }}</TableCell>
                  <TableCell class="text-right italic" :class="corValor(dados.saldo_projetado)">{{ moeda(dados.saldo_projetado) }}</TableCell>
                </template>
              </TableRow>
            </TableFooter>
          </Table>
        </CardContent>
      </Card>

      <section class="grid gap-4 md:grid-cols-2">
        <Card v-for="lado in [['entradas', 'Entradas por categoria', 'var(--chart-1)'], ['saidas', 'Saídas por categoria', 'var(--chart-2)']]" :key="lado[0]">
          <CardHeader><CardTitle class="text-base">{{ lado[1] }}</CardTitle><CardDescription>Realizado no período.</CardDescription></CardHeader>
          <CardContent>
            <ul v-if="dados.por_categoria[lado[0]].length" class="space-y-2.5">
              <li v-for="c in dados.por_categoria[lado[0]]" :key="c.categoria_id" class="text-sm">
                <div class="mb-1 flex justify-between gap-2"><span class="truncate">{{ c.categoria }}</span><span class="tabular-nums">{{ moeda(c.total) }}</span></div>
                <div class="h-2 rounded-full bg-muted"><div class="h-2 rounded-full" :style="{ width: `${(c.total / maior(dados.por_categoria[lado[0]])) * 100}%`, background: lado[2] }" /></div>
              </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">Nada no período.</p>
          </CardContent>
        </Card>
      </section>
    </template>
  </main>
</template>
