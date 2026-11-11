<script setup>
import { computed, ref, watch } from 'vue'
import { Beef, Flag, Milk, TriangleAlert, TrendingDown, TrendingUp, Minus } from '@lucide/vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import AvatarAnimal from '../components/AvatarAnimal.vue'
import FiltroPeriodo from '../components/FiltroPeriodo.vue'
import GraficoPizzaProducao from '../components/GraficoPizzaProducao.vue'
import GraficoProducaoDiaria from '../components/GraficoProducaoDiaria.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { dataBr, hoje, numero } from '../formatos'

/** Painel de produção de leite: destaque, indicadores, série diária e comparativo por vaca. */

// Período geral (indicadores + série diária): atalhos ou intervalo personalizado
const menos = (n) => { const d = new Date(`${hoje()}T12:00:00`); d.setDate(d.getDate() - (n - 1)); return d.toISOString().slice(0, 10) }
const periodo = ref({ inicio: menos(30), fim: hoje() })
const dados = ref(null)
const carregando = ref(false)
const erros = ref([])
const verTabela = ref(false)

async function carregar() {
  carregando.value = true
  try {
    dados.value = (await http.get('/painel/leite', { params: periodo.value })).data.data
    erros.value = []
  } catch (e) {
    erros.value = e.mensagens
  } finally {
    carregando.value = false
  }
}
watch(periodo, carregar, { immediate: true, deep: true })

// Comparativo por vaca: período próprio (atalhos ou intervalo personalizado)
const periodoComparativo = ref({ inicio: menos(30), fim: hoje() })
const comparativo = ref(null)
const carregandoComp = ref(false)
const errosComp = ref([])
async function carregarComparativo() {
  carregandoComp.value = true
  try {
    comparativo.value = (await http.get('/painel/leite/comparativo', { params: periodoComparativo.value })).data.data
    errosComp.value = []
  } catch (e) {
    errosComp.value = e.mensagens
  } finally {
    carregandoComp.value = false
  }
}
watch(periodoComparativo, carregarComparativo, { immediate: true, deep: true })

// Avisos da engorda (metas, lotes sem pesagem, animais perdendo peso).
const avisosEngorda = ref([])
http.get('/painel/engorda').then((resp) => (avisosEngorda.value = resp.data.data)).catch(() => { /* o painel de leite segue */ })
const destinoAviso = (a) => (a.animal_id ? `/animais/${a.animal_id}` : `/lotes/${a.lote_id}`)

const r = computed(() => dados.value?.resumo)
const deltaUltimo = computed(() => {
  if (!r.value?.ultimo_dia || !r.value.media_diaria) return null
  return ((r.value.ultimo_dia.total - r.value.media_diaria) / r.value.media_diaria) * 100
})
const maiorMedia = computed(() => Math.max(...(comparativo.value?.ranking ?? []).map((v) => v.media_diaria), 1))
const LIMITE_QUEDA = -10

/** Delta assinado: sobe = bom, cai = ruim. Ícone + texto, nunca só cor. */
const delta = (v) => v == null ? null : {
  texto: `${v > 0 ? '+' : ''}${numero(v, 1)}%`,
  icone: v > 0 ? TrendingUp : v < 0 ? TrendingDown : Minus,
  classe: v > 0 ? 'text-emerald-700 dark:text-emerald-400' : v < 0 ? 'text-destructive' : 'text-muted-foreground',
}
</script>

<template>
  <CabecalhoPagina titulo="Produção de leite">
    <template #acoes>
      <FiltroPeriodo v-model="periodo" />
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erros" />

    <Card v-if="avisosEngorda.length" class="gap-3 border-amber-300/60 dark:border-amber-900">
      <CardHeader>
        <CardTitle class="flex items-center gap-2 text-base"><Beef class="size-4" /> Engorda: {{ avisosEngorda.length }} aviso(s)</CardTitle>
      </CardHeader>
      <CardContent>
        <ul class="space-y-2 text-sm">
          <li v-for="(a, i) in avisosEngorda" :key="i">
            <RouterLink :to="destinoAviso(a)" class="flex items-start gap-2 hover:underline">
              <component :is="a.nivel === 'INFO' ? Flag : TriangleAlert" class="mt-0.5 size-4 shrink-0" :class="a.nivel === 'INFO' ? 'text-sky-600 dark:text-sky-400' : 'text-amber-600 dark:text-amber-400'" />
              {{ a.mensagem }}
            </RouterLink>
          </li>
        </ul>
      </CardContent>
    </Card>

    <template v-if="dados">
      <div class="grid gap-4 transition-opacity lg:grid-cols-[1.2fr_2fr]" :class="{ 'opacity-50': carregando }">
        <Card>
          <CardHeader>
            <CardDescription>Produção {{ r.ultimo_dia ? `em ${dataBr(r.ultimo_dia.data)}` : 'do último dia' }}</CardDescription>
            <CardTitle class="text-5xl font-semibold tracking-tight">
              {{ r.ultimo_dia ? numero(r.ultimo_dia.total, 1) : '—' }}<span class="ml-1 text-2xl font-normal text-muted-foreground">L</span>
            </CardTitle>
          </CardHeader>
          <CardContent class="space-y-1 text-sm">
            <p v-if="deltaUltimo !== null" class="flex flex-wrap items-center gap-1">
              <span class="inline-flex items-center gap-1 font-medium" :class="delta(deltaUltimo).classe">
                <component :is="delta(deltaUltimo).icone" class="size-4" />{{ delta(deltaUltimo).texto }}
              </span>
              <span class="text-muted-foreground">vs. média diária do período ({{ dados.periodo.dias }} dias)</span>
            </p>
            <p v-if="r.ultimo_dia" class="text-muted-foreground">{{ r.ultimo_dia.vacas }} vaca(s) ordenhada(s)</p>
          </CardContent>
        </Card>

        <div class="grid grid-cols-2 gap-4">
          <Card class="gap-2">
            <CardHeader><CardDescription>Média diária</CardDescription><CardTitle class="text-2xl">{{ numero(r.media_diaria, 1) }} L</CardTitle></CardHeader>
            <CardContent v-if="delta(r.variacao_pct)" class="text-xs">
              <span class="inline-flex items-center gap-1 font-medium" :class="delta(r.variacao_pct).classe">
                <component :is="delta(r.variacao_pct).icone" class="size-3.5" />{{ delta(r.variacao_pct).texto }}
              </span>
              <span class="text-muted-foreground"> vs. {{ dados.periodo.dias }} dias anteriores</span>
            </CardContent>
          </Card>
          <Card class="gap-2">
            <CardHeader><CardDescription>Média por vaca/dia</CardDescription><CardTitle class="text-2xl">{{ r.media_por_vaca_dia ? `${numero(r.media_por_vaca_dia, 1)} L` : '—' }}</CardTitle></CardHeader>
            <CardContent class="text-xs text-muted-foreground">Total no período: {{ numero(r.total_litros, 0) }} L</CardContent>
          </Card>
          <Card class="gap-2">
            <CardHeader><CardDescription>Vacas em lactação</CardDescription><CardTitle class="text-2xl">{{ r.vacas_em_lactacao }}</CardTitle></CardHeader>
            <CardContent><Button as-child variant="link" class="h-auto p-0 text-xs"><RouterLink to="/ordenha"><Milk class="size-3.5" /> Lançar ordenha</RouterLink></Button></CardContent>
          </Card>
          <Card class="gap-2" :class="{ 'border-destructive/40 bg-destructive/5': r.litros_em_carencia > 0 }">
            <CardHeader>
              <CardDescription>Leite em carência (descarte)</CardDescription>
              <CardTitle class="flex items-center gap-1.5 text-2xl" :class="{ 'text-destructive': r.litros_em_carencia > 0 }">
                <TriangleAlert v-if="r.litros_em_carencia > 0" class="size-5" />{{ numero(r.litros_em_carencia, 1) }} L
              </CardTitle>
            </CardHeader>
            <CardContent class="text-xs text-muted-foreground">
              {{ r.total_litros ? `${numero((r.litros_em_carencia / r.total_litros) * 100, 1)}% da produção — ` : '' }}não comercializável
            </CardContent>
          </Card>
        </div>
      </div>

      <Card :class="{ 'opacity-50': carregando }">
        <CardHeader class="flex flex-wrap items-start justify-between gap-3">
          <div class="space-y-1.5">
            <CardTitle>Produção diária</CardTitle>
            <CardDescription>{{ dataBr(dados.periodo.inicio) }} a {{ dataBr(dados.periodo.fim) }}, em litros</CardDescription>
          </div>
          <div class="flex items-center gap-4 text-xs text-muted-foreground">
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-[2px] bg-chart-1" />Manhã</span>
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-[2px] bg-chart-2" />Tarde</span>
            <Button variant="link" size="sm" class="h-auto p-0 text-xs" @click="verTabela = !verTabela">{{ verTabela ? 'Ver gráfico' : 'Ver tabela' }}</Button>
          </div>
        </CardHeader>
        <CardContent>
          <GraficoProducaoDiaria v-if="!verTabela" :serie="dados.serie" />
          <div v-else class="max-h-72 overflow-y-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Data</TableHead><TableHead class="text-right">Manhã</TableHead><TableHead class="text-right">Tarde</TableHead>
                  <TableHead class="text-right">Total</TableHead><TableHead class="text-right">Vacas</TableHead><TableHead class="text-right">Em carência</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody class="tabular-nums">
                <TableRow v-for="d in [...dados.serie].reverse()" :key="d.data">
                  <TableCell>{{ dataBr(d.data) }}</TableCell>
                  <TableCell class="text-right">{{ numero(d.manha, 1) }}</TableCell>
                  <TableCell class="text-right">{{ numero(d.tarde, 1) }}</TableCell>
                  <TableCell class="text-right font-medium">{{ numero(d.total, 1) }}</TableCell>
                  <TableCell class="text-right">{{ d.vacas }}</TableCell>
                  <TableCell class="text-right" :class="{ 'text-destructive': d.em_carencia > 0 }">{{ d.em_carencia > 0 ? numero(d.em_carencia, 1) : '—' }}</TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>
    </template>

    <!-- Comparativo por vaca, com período próprio -->
    <Card>
      <CardHeader class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1.5">
          <CardTitle>Comparativo por vaca</CardTitle>
          <CardDescription>
            <template v-if="comparativo">
              {{ dataBr(comparativo.periodo.inicio) }} a {{ dataBr(comparativo.periodo.fim) }} ({{ comparativo.periodo.dias }} dias) ·
              {{ numero(comparativo.total_litros, 0) }} L no total
            </template>
          </CardDescription>
        </div>
        <FiltroPeriodo v-model="periodoComparativo" />
      </CardHeader>
      <CardContent class="space-y-4">
        <AlertaErros :mensagens="errosComp" />
        <div v-if="comparativo" class="grid gap-8 transition-opacity xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]" :class="{ 'opacity-50': carregandoComp }">
          <section>
            <h3 class="mb-3 text-sm font-medium">Participação no total</h3>
            <GraficoPizzaProducao :ranking="comparativo.ranking" />
          </section>

          <section>
            <h3 class="text-sm font-medium">Média diária por vaca</h3>
            <p class="mb-3 text-xs text-muted-foreground">
              Variação vs. os {{ comparativo.periodo.dias }} dias anteriores · quedas acima de {{ -LIMITE_QUEDA }}% em destaque
            </p>
            <p v-if="!comparativo.ranking.length" class="text-sm text-muted-foreground">Nenhuma ordenha registrada no período.</p>
            <ul class="space-y-0.5">
              <li v-for="v in comparativo.ranking" :key="v.animal_id">
                <RouterLink :to="`/animais/${v.animal_id}`" class="grid grid-cols-[auto_minmax(0,7rem)_1fr] items-center gap-x-3 gap-y-1 rounded-md px-2 py-1.5 hover:bg-accent sm:grid-cols-[auto_9rem_1fr_10rem]">
                  <AvatarAnimal :animal="v" :tamanho="32" />
                  <span class="min-w-0">
                    <span class="block truncate text-sm font-medium">{{ v.nome || v.brinco }}</span>
                    <span class="block font-mono text-xs text-muted-foreground">{{ v.brinco }}</span>
                  </span>
                  <span class="flex items-center gap-2">
                    <span class="min-w-0 flex-1"><span class="block h-3 rounded-r-[4px] bg-chart-1" :style="{ width: `${(v.media_diaria / maiorMedia) * 100}%` }" /></span>
                    <span class="w-14 shrink-0 text-sm font-medium tabular-nums">{{ numero(v.media_diaria, 1) }} L</span>
                  </span>
                  <span class="col-span-3 flex items-center justify-end gap-1.5 text-xs whitespace-nowrap sm:col-span-1">
                    <Badge v-if="v.em_carencia" variant="destructive" class="px-1.5"><TriangleAlert /> carência</Badge>
                    <span
                      v-if="delta(v.variacao_pct)" class="inline-flex items-center gap-0.5"
                      :class="[delta(v.variacao_pct).classe, { 'rounded bg-destructive/10 px-1.5 py-0.5 font-semibold': v.variacao_pct <= LIMITE_QUEDA }]"
                      :title="v.variacao_pct <= LIMITE_QUEDA ? 'Queda acentuada: verifique saúde/mastite' : ''"
                    ><component :is="delta(v.variacao_pct).icone" class="size-3.5" />{{ delta(v.variacao_pct).texto }}</span>
                    <span v-else class="text-muted-foreground">sem base</span>
                  </span>
                </RouterLink>
              </li>
            </ul>
          </section>
        </div>
      </CardContent>
    </Card>
  </main>
</template>
