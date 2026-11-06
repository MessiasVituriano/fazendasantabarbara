<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CircleCheck, Plus } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import http from '../../api/http'
import AlertaErros from '../../components/AlertaErros.vue'
import DetalheLancamento from '../../components/financeiro/DetalheLancamento.vue'
import DialogoBaixa from '../../components/financeiro/DialogoBaixa.vue'
import FormLancamento from '../../components/financeiro/FormLancamento.vue'
import SeloSituacao from '../../components/financeiro/SeloSituacao.vue'
import CabecalhoPagina from '../../components/layout/CabecalhoPagina.vue'
import { dataBr, hoje, moeda } from '../../formatos'

/**
 * Contas a pagar e a receber: o que está em aberto, agrupado por vencimento (vencidas primeiro),
 * com baixa individual ou de vários de uma vez; e o que foi pago/recebido nos últimos 30 dias.
 */
const route = useRoute()
const router = useRouter()
const tipo = ref(route.query.tipo === 'RECEITA' ? 'RECEITA' : 'DESPESA')
const visao = ref(route.query.visao === 'pagos' ? 'pagos' : 'aberto')
watch([tipo, visao], ([t, v]) => router.replace({ query: { tipo: t === 'RECEITA' ? t : undefined, visao: v === 'pagos' ? v : undefined } }))

const resumo = ref(null)
const itens = ref([])
const erros = ref([])
const carregando = ref(false)
const selecionados = ref([])

const mais = (n) => { const d = new Date(`${hoje()}T12:00:00`); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10) }

async function carregar() {
  carregando.value = true
  selecionados.value = []
  try {
    const params = visao.value === 'aberto'
      ? { tipo: tipo.value, situacao: ['EM_ABERTO'], base: 'vencimento', ordem: 'asc', por_pagina: 500 }
      : { tipo: tipo.value, situacao: ['PAGO'], base: 'pagamento', inicio: mais(-30), fim: hoje(), ordem: 'desc', por_pagina: 500 }
    const [r, l] = await Promise.all([http.get('/financeiro/resumo'), http.get('/financeiro/lancamentos', { params })])
    resumo.value = r.data.data
    itens.value = l.data.data
    erros.value = []
  } catch (e) { erros.value = e.mensagens } finally { carregando.value = false }
}
watch([tipo, visao], carregar, { immediate: true })

const agenda = computed(() => resumo.value?.[tipo.value === 'RECEITA' ? 'a_receber' : 'a_pagar'])
const ehReceita = computed(() => tipo.value === 'RECEITA')

const GRUPOS = [
  { chave: 'vencido', titulo: 'Vencidas', cor: 'text-red-700 dark:text-red-400' },
  { chave: 'hoje', titulo: 'Vencem hoje', cor: 'text-amber-700 dark:text-amber-400' },
  { chave: 'proximos_7_dias', titulo: 'Próximos 7 dias', cor: '' },
  { chave: 'proximos_30_dias', titulo: 'De 8 a 30 dias', cor: '' },
  { chave: 'depois', titulo: 'Mais de 30 dias', cor: 'text-muted-foreground' },
]
const faixa = (venc) => {
  const h = hoje()
  if (venc < h) return 'vencido'
  if (venc === h) return 'hoje'
  if (venc <= mais(7)) return 'proximos_7_dias'
  if (venc <= mais(30)) return 'proximos_30_dias'
  return 'depois'
}
const grupos = computed(() => GRUPOS.map((g) => {
  const lista = itens.value.filter((l) => faixa(l.data_vencimento) === g.chave)
  return { ...g, lista, total: lista.reduce((s, l) => s + Number(l.valor), 0) }
}).filter((g) => g.lista.length))

const diasAtraso = (venc) => Math.round((new Date(`${hoje()}T12:00:00`) - new Date(`${venc}T12:00:00`)) / 86400000)

const selecionadosObj = computed(() => itens.value.filter((l) => selecionados.value.includes(l.id)))
const totalSelecionado = computed(() => selecionadosObj.value.reduce((s, l) => s + Number(l.valor), 0))
const todosDoGrupo = (g) => g.lista.every((l) => selecionados.value.includes(l.id))
function alternarGrupo(g) {
  const ids = g.lista.map((l) => l.id)
  selecionados.value = todosDoGrupo(g) ? selecionados.value.filter((id) => !ids.includes(id)) : [...new Set([...selecionados.value, ...ids])]
}

const baixaAberta = ref(false)
const paraBaixar = ref([])
function baixar(lista) {
  paraBaixar.value = lista
  baixaAberta.value = true
}
const novoAberto = ref(false)
const detalheId = ref(null)
const totalPagos = computed(() => itens.value.reduce((s, l) => s + Number(l.valor_pago ?? 0), 0))
</script>

<template>
  <CabecalhoPagina titulo="Contas a pagar e receber">
    <template #acoes>
      <Button @click="novoAberto = true"><Plus /> {{ ehReceita ? 'Nova conta a receber' : 'Nova conta a pagar' }}</Button>
    </template>
    <template #filtros>
      <ToggleGroup v-model="tipo" type="single" variant="outline" size="sm" aria-label="Tipo" @update:model-value="(v) => !v && (tipo = 'DESPESA')">
        <ToggleGroupItem value="DESPESA" class="px-3">A pagar</ToggleGroupItem>
        <ToggleGroupItem value="RECEITA" class="px-3">A receber</ToggleGroupItem>
      </ToggleGroup>
      <ToggleGroup v-model="visao" type="single" variant="outline" size="sm" aria-label="Visão" @update:model-value="(v) => !v && (visao = 'aberto')">
        <ToggleGroupItem value="aberto" class="px-3">Em aberto</ToggleGroupItem>
        <ToggleGroupItem value="pagos" class="px-3">{{ ehReceita ? 'Recebidas' : 'Pagas' }} (30 dias)</ToggleGroupItem>
      </ToggleGroup>
    </template>
  </CabecalhoPagina>

  <main class="space-y-5 px-4 py-6 pb-28 md:px-6">
    <AlertaErros :mensagens="erros" />

    <div v-if="agenda" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <Card class="py-4" :class="agenda.vencido.quantidade ? 'border-red-300 dark:border-red-900' : ''"><CardContent class="px-4">
        <p class="text-xs text-muted-foreground">Vencidas</p>
        <p class="text-xl font-semibold tabular-nums" :class="agenda.vencido.quantidade ? 'text-red-700 dark:text-red-400' : ''">{{ moeda(agenda.vencido.valor) }}</p>
        <p class="text-xs text-muted-foreground">{{ agenda.vencido.quantidade }} conta(s)</p>
      </CardContent></Card>
      <Card class="py-4"><CardContent class="px-4">
        <p class="text-xs text-muted-foreground">Vencem hoje</p>
        <p class="text-xl font-semibold tabular-nums">{{ moeda(agenda.hoje.valor) }}</p>
        <p class="text-xs text-muted-foreground">{{ agenda.hoje.quantidade }} conta(s)</p>
      </CardContent></Card>
      <Card class="py-4"><CardContent class="px-4">
        <p class="text-xs text-muted-foreground">Próximos 7 dias</p>
        <p class="text-xl font-semibold tabular-nums">{{ moeda(agenda.proximos_7_dias.valor) }}</p>
        <p class="text-xs text-muted-foreground">{{ agenda.proximos_7_dias.quantidade }} conta(s)</p>
      </CardContent></Card>
      <Card class="py-4"><CardContent class="px-4">
        <p class="text-xs text-muted-foreground">Total em aberto</p>
        <p class="text-xl font-semibold tabular-nums">{{ moeda(agenda.total.valor) }}</p>
        <p class="text-xs text-muted-foreground">{{ agenda.total.quantidade }} conta(s)</p>
      </CardContent></Card>
    </div>

    <!-- Em aberto, por faixa de vencimento -->
    <template v-if="visao === 'aberto'">
      <section v-for="g in grupos" :key="g.chave">
        <div class="mb-2 flex items-center gap-3">
          <label class="flex shrink-0 pointer-coarse:-m-2.5 pointer-coarse:p-2.5">
            <input type="checkbox" class="size-4 accent-primary" :checked="todosDoGrupo(g)" :aria-label="`Selecionar ${g.titulo}`" @change="alternarGrupo(g)" />
          </label>
          <h2 class="text-sm font-semibold" :class="g.cor">{{ g.titulo }}</h2>
          <span class="text-sm tabular-nums text-muted-foreground">{{ g.lista.length }} · {{ moeda(g.total) }}</span>
        </div>
        <Card class="gap-0 divide-y py-0">
          <div v-for="l in g.lista" :key="l.id" class="flex items-center gap-3 px-4 py-3">
            <label class="flex shrink-0 pointer-coarse:-m-2.5 pointer-coarse:p-2.5">
              <input v-model="selecionados" type="checkbox" :value="l.id" class="size-4 shrink-0 accent-primary" :aria-label="`Selecionar ${l.descricao}`" />
            </label>
            <button class="min-w-0 flex-1 text-left pointer-coarse:min-h-11" @click="detalheId = l.id">
              <p class="truncate font-medium">{{ l.descricao }}</p>
              <p class="truncate text-xs text-muted-foreground">
                Vence {{ dataBr(l.data_vencimento) }}
                <span v-if="l.situacao === 'VENCIDO'" class="text-red-700 dark:text-red-400">· {{ diasAtraso(l.data_vencimento) }} dia(s) em atraso</span>
                <template v-if="l.pessoa"> · {{ l.pessoa }}</template>
                · {{ l.categoria }}<template v-if="l.lote"> · {{ l.lote }}</template>
              </p>
            </button>
            <span class="hidden text-right font-semibold tabular-nums sm:block">{{ moeda(l.valor) }}</span>
            <Button size="sm" variant="outline" @click="baixar([l])">
              <CircleCheck /> <span class="hidden md:inline">{{ ehReceita ? 'Receber' : 'Pagar' }}</span>
              <span class="tabular-nums sm:hidden">{{ moeda(l.valor) }}</span>
            </Button>
          </div>
        </Card>
      </section>
      <p v-if="!grupos.length && !carregando" class="py-10 text-center text-sm text-muted-foreground">
        Nenhuma conta {{ ehReceita ? 'a receber' : 'a pagar' }} em aberto. 🎉
      </p>
    </template>

    <!-- Pagas/recebidas nos últimos 30 dias -->
    <template v-else>
      <p class="text-sm text-muted-foreground">{{ itens.length }} lançamento(s) · {{ moeda(totalPagos) }} {{ ehReceita ? 'recebidos' : 'pagos' }} nos últimos 30 dias</p>
      <Card class="gap-0 divide-y py-0">
        <button v-for="l in itens" :key="l.id" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-accent/50" @click="detalheId = l.id">
          <div class="min-w-0 flex-1">
            <p class="truncate font-medium">{{ l.descricao }}</p>
            <p class="truncate text-xs text-muted-foreground">
              {{ ehReceita ? 'Recebido' : 'Pago' }} em {{ dataBr(l.data_pagamento) }} · {{ l.conta }}<template v-if="l.forma_pagamento_label"> · {{ l.forma_pagamento_label }}</template>
              <template v-if="l.pessoa"> · {{ l.pessoa }}</template>
            </p>
          </div>
          <SeloSituacao class="hidden sm:inline-flex" :situacao="l.situacao" :tipo="l.tipo" />
          <span class="font-semibold tabular-nums">{{ moeda(l.valor_pago) }}</span>
        </button>
        <p v-if="!itens.length && !carregando" class="px-4 py-10 text-center text-sm text-muted-foreground">Nada {{ ehReceita ? 'recebido' : 'pago' }} nos últimos 30 dias.</p>
      </Card>
    </template>
  </main>

  <!-- Barra de ação da seleção -->
  <div v-if="selecionados.length" class="fixed inset-x-0 bottom-0 z-30 border-t bg-background/95 backdrop-blur md:left-auto md:right-6 md:bottom-6 md:rounded-xl md:border md:shadow-lg">
    <div class="flex items-center gap-3 px-4 py-3">
      <span class="text-sm"><strong>{{ selecionados.length }}</strong> selecionado(s) · <strong class="tabular-nums">{{ moeda(totalSelecionado) }}</strong></span>
      <Button variant="ghost" size="sm" @click="selecionados = []">Limpar</Button>
      <Button size="sm" @click="baixar(selecionadosObj)"><CircleCheck /> {{ ehReceita ? 'Receber' : 'Pagar' }} selecionados</Button>
    </div>
  </div>

  <DialogoBaixa v-model:open="baixaAberta" :lancamentos="paraBaixar" @baixado="carregar" />
  <FormLancamento v-model:open="novoAberto" :padrao="{ tipo, data_vencimento: hoje() }" @salvo="carregar" />
  <DetalheLancamento :id="detalheId" @fechar="detalheId = null" @alterado="carregar" />
</template>
