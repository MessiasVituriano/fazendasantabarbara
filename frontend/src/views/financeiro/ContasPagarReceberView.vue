<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Ban, CircleCheck, Clock, Pencil, Plus, TriangleAlert } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import http from '../../api/http'
import AlertaErros from '../../components/AlertaErros.vue'
import DetalheLancamento from '../../components/financeiro/DetalheLancamento.vue'
import DialogoBaixa from '../../components/financeiro/DialogoBaixa.vue'
import DialogoCancelamento from '../../components/financeiro/DialogoCancelamento.vue'
import FormLancamento from '../../components/financeiro/FormLancamento.vue'
import SeloSituacao from '../../components/financeiro/SeloSituacao.vue'
import CabecalhoPagina from '../../components/layout/CabecalhoPagina.vue'
import { dataBr, fimDoMes, hoje, inicioDoMes, moeda } from '../../formatos'

/**
 * Contas a pagar e a receber: lista única por vencimento (por padrão, o mês atual) em que a situação de cada
 * conta aparece no selo: No dia, Atrasado ou Pago. Os filtros ficam na URL e os avisos do topo consideram
 * todas as contas em aberto, mesmo as de fora do período filtrado.
 */
const route = useRoute()
const router = useRouter()
const TODAS = '_'

const filtros = ref({
  tipo: route.query.tipo === 'RECEITA' ? 'RECEITA' : 'DESPESA',
  situacao: ['PENDENTE', 'VENCIDO', 'PAGO'].includes(route.query.situacao) ? route.query.situacao : TODAS,
  inicio: route.query.inicio ?? inicioDoMes(),
  fim: route.query.fim ?? fimDoMes(),
})
const ehReceita = computed(() => filtros.value.tipo === 'RECEITA')

const resumo = ref(null)
const itens = ref([])
const meta = ref(null)
const erros = ref([])
const carregando = ref(false)
const selecionados = ref([])

const mais = (n) => { const d = new Date(`${hoje()}T12:00:00`); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10) }
const emAberto = (l) => ['PENDENTE', 'VENCIDO'].includes(l.situacao)

async function carregar() {
  const f = filtros.value
  carregando.value = true
  try {
    const params = {
      tipo: f.tipo,
      situacao: f.situacao === TODAS ? undefined : [f.situacao],
      base: 'vencimento',
      inicio: f.inicio || undefined,
      fim: f.fim || undefined,
      ordem: 'asc',
      por_pagina: 500,
    }
    const [r, l] = await Promise.all([http.get('/financeiro/resumo'), http.get('/financeiro/lancamentos', { params })])
    resumo.value = r.data.data
    itens.value = l.data.data
    meta.value = l.data.meta
    // Mantém a seleção ao editar (o total é refeito com o valor novo); o que foi pago ou cancelado sai dela.
    selecionados.value = selecionados.value.filter((id) => itens.value.some((i) => i.id === id && emAberto(i)))
    erros.value = []
  } catch (e) { erros.value = e.mensagens } finally { carregando.value = false }
}

watch(filtros, (f) => {
  router.replace({
    query: {
      tipo: f.tipo === 'RECEITA' ? f.tipo : undefined,
      situacao: f.situacao === TODAS ? undefined : f.situacao,
      inicio: f.inicio === inicioDoMes() ? undefined : f.inicio,
      fim: f.fim === fimDoMes() ? undefined : f.fim,
    },
  })
  selecionados.value = []
  carregar()
}, { deep: true })
carregar()

function aplicarFiltro(situacao, inicio, fim) {
  filtros.value = { ...filtros.value, situacao, inicio, fim }
}
const limpar = () => aplicarFiltro(TODAS, '', '')
const esteMes = () => aplicarFiltro(TODAS, inicioDoMes(), fimDoMes())
const verAtrasadas = () => aplicarFiltro('VENCIDO', '', '')
const verProximas = () => aplicarFiltro('PENDENTE', hoje(), mais(7))

const agenda = computed(() => resumo.value?.[ehReceita.value ? 'a_receber' : 'a_pagar'])
const qtdAtrasadas = computed(() => agenda.value?.vencido.quantidade ?? 0)
const qtdProximas = computed(() => (agenda.value?.hoje.quantidade ?? 0) + (agenda.value?.proximos_7_dias.quantidade ?? 0))

const totais = computed(() => meta.value?.totais)
const totalGeral = computed(() => (totais.value?.pago ?? 0) + (totais.value?.pendente ?? 0))

/** Destaque da linha: vermelho se atrasada, âmbar se ainda no dia, mas vencendo em até 7 dias. */
function corLinha(l) {
  if (l.situacao === 'VENCIDO') return 'bg-red-50 hover:bg-red-100 dark:bg-red-950/40 dark:hover:bg-red-950/60'
  if (l.situacao === 'PENDENTE' && l.data_vencimento <= mais(7)) return 'bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/30 dark:hover:bg-amber-950/50'
  return 'hover:bg-accent/50'
}
const diasAtraso = (venc) => Math.round((new Date(`${hoje()}T12:00:00`) - new Date(`${venc}T12:00:00`)) / 86400000)

const abertos = computed(() => itens.value.filter(emAberto))
const selecionadosObj = computed(() => itens.value.filter((l) => selecionados.value.includes(l.id)))
const totalSelecionado = computed(() => selecionadosObj.value.reduce((s, l) => s + Number(l.valor), 0))
const todosSelecionados = computed(() => abertos.value.length > 0 && abertos.value.every((l) => selecionados.value.includes(l.id)))
function alternarTodos() {
  selecionados.value = todosSelecionados.value ? [] : abertos.value.map((l) => l.id)
}

const baixaAberta = ref(false)
const paraBaixar = ref([])
function baixar(lista) {
  paraBaixar.value = lista
  baixaAberta.value = true
}
const formAberto = ref(false)
const emEdicao = ref(null)
function abrirForm(lancamento = null) {
  emEdicao.value = lancamento
  formAberto.value = true
}
const cancelamentoAberto = ref(false)
const paraCancelar = ref(null)
function cancelar(lancamento) {
  paraCancelar.value = lancamento
  cancelamentoAberto.value = true
}
const detalheId = ref(null)
</script>

<template>
  <CabecalhoPagina titulo="Contas a pagar e receber">
    <template #acoes>
      <Button @click="abrirForm()"><Plus /> {{ ehReceita ? 'Nova conta a receber' : 'Nova conta a pagar' }}</Button>
    </template>
    <template #filtros>
      <ToggleGroup v-model="filtros.tipo" type="single" variant="outline" size="sm" aria-label="Tipo" @update:model-value="(v) => !v && (filtros.tipo = 'DESPESA')">
        <ToggleGroupItem value="DESPESA" class="px-3">A pagar</ToggleGroupItem>
        <ToggleGroupItem value="RECEITA" class="px-3">A receber</ToggleGroupItem>
      </ToggleGroup>
      <Select v-model="filtros.situacao">
        <SelectTrigger size="sm" class="w-44" aria-label="Status"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem :value="TODAS">Todos os status</SelectItem>
          <SelectItem value="PENDENTE">No dia</SelectItem>
          <SelectItem value="PAGO">{{ ehReceita ? 'Recebido' : 'Pago' }}</SelectItem>
          <SelectItem value="VENCIDO">Atrasado</SelectItem>
        </SelectContent>
      </Select>
      <div class="flex items-center gap-2">
        <Input v-model="filtros.inicio" type="date" class="h-8 w-38" aria-label="Vencimento de" />
        <span class="text-sm text-muted-foreground">a</span>
        <Input v-model="filtros.fim" type="date" :min="filtros.inicio" class="h-8 w-38" aria-label="Vencimento até" />
      </div>
      <Button variant="ghost" size="sm" @click="esteMes">Este mês</Button>
      <Button variant="ghost" size="sm" @click="limpar">Limpar</Button>
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 pb-28 md:px-6">
    <AlertaErros :mensagens="erros" />

    <!-- Avisos: valem para todas as contas em aberto; ao clicar, mostram exatamente essas contas. -->
    <div v-if="qtdAtrasadas || qtdProximas" class="flex flex-wrap gap-2">
      <button v-if="qtdAtrasadas" class="inline-flex items-center gap-1.5 rounded-md bg-red-700 px-2.5 py-1 text-sm font-medium text-white hover:bg-red-800 pointer-coarse:min-h-11" @click="verAtrasadas">
        <TriangleAlert class="size-4" /> {{ qtdAtrasadas }} atrasada(s)
      </button>
      <button v-if="qtdProximas" class="inline-flex items-center gap-1.5 rounded-md bg-amber-300 px-2.5 py-1 text-sm font-medium text-amber-950 hover:bg-amber-400 pointer-coarse:min-h-11" @click="verProximas">
        <Clock class="size-4" /> {{ qtdProximas }} vencendo em 7 dias
      </button>
    </div>

    <div v-if="totais" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <Card class="border-l-4 border-l-slate-400 py-4"><CardContent class="px-4">
        <p class="text-xs text-muted-foreground">Total geral</p>
        <p class="text-xl font-semibold tabular-nums">{{ moeda(totalGeral) }}</p>
      </CardContent></Card>
      <Card class="border-l-4 border-l-emerald-500 py-4"><CardContent class="px-4">
        <p class="text-xs text-muted-foreground">Total {{ ehReceita ? 'recebido' : 'pago' }}</p>
        <p class="text-xl font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">{{ moeda(totais.pago) }}</p>
      </CardContent></Card>
      <Card class="border-l-4 border-l-amber-500 py-4"><CardContent class="px-4">
        <p class="text-xs text-muted-foreground">Total pendente</p>
        <p class="text-xl font-semibold tabular-nums text-amber-700 dark:text-amber-400">{{ moeda(totais.pendente) }}</p>
      </CardContent></Card>
      <Card class="border-l-4 border-l-sky-500 py-4"><CardContent class="px-4">
        <p class="text-xs text-muted-foreground">Contas no filtro</p>
        <p class="text-xl font-semibold tabular-nums">{{ totais.quantidade }}</p>
      </CardContent></Card>
    </div>

    <!-- Celular: lista em cartões -->
    <Card class="gap-0 divide-y overflow-hidden py-0 sm:hidden">
      <div v-for="l in itens" :key="l.id" class="px-4 py-3" :class="corLinha(l)">
        <div class="flex items-start gap-3">
          <label v-if="emAberto(l)" class="flex shrink-0 pt-0.5 pointer-coarse:-m-2.5 pointer-coarse:p-2.5">
            <input v-model="selecionados" type="checkbox" :value="l.id" class="size-4 accent-primary" :aria-label="`Selecionar ${l.descricao}`" />
          </label>
          <button class="flex min-w-0 flex-1 flex-col gap-1 text-left" @click="detalheId = l.id">
            <span class="flex w-full items-start justify-between gap-3">
              <span class="min-w-0 truncate font-medium">{{ l.descricao }}</span>
              <span class="shrink-0 font-semibold tabular-nums">{{ moeda(l.valor) }}</span>
            </span>
            <span class="block w-full truncate text-xs text-muted-foreground">
              <template v-if="l.situacao === 'PAGO'">{{ ehReceita ? 'Recebido' : 'Pago' }} em {{ dataBr(l.data_pagamento) }}: {{ moeda(l.valor_pago) }}</template>
              <template v-else>{{ l.pessoa ?? l.categoria }}</template>
            </span>
            <span class="flex w-full items-center justify-between gap-3">
              <SeloSituacao :situacao="l.situacao" :tipo="l.tipo" />
              <span class="text-xs tabular-nums text-muted-foreground">
                Vence {{ dataBr(l.data_vencimento) }}
                <span v-if="l.situacao === 'VENCIDO'" class="text-red-700 dark:text-red-400">· {{ diasAtraso(l.data_vencimento) }} dia(s)</span>
              </span>
            </span>
          </button>
        </div>
        <div class="mt-2 flex items-center justify-end gap-1">
          <Button size="icon-sm" variant="ghost" title="Editar" :aria-label="`Editar ${l.descricao}`" @click="abrirForm(l)"><Pencil /></Button>
          <template v-if="emAberto(l)">
            <Button size="icon-sm" variant="ghost" class="text-destructive" title="Cancelar" :aria-label="`Cancelar ${l.descricao}`" @click="cancelar(l)"><Ban /></Button>
            <Button size="sm" variant="outline" class="border-emerald-600 text-emerald-700 dark:text-emerald-400" @click="baixar([l])"><CircleCheck /> {{ ehReceita ? 'Receber' : 'Pagar' }}</Button>
          </template>
        </div>
      </div>
      <p v-if="!itens.length && !carregando" class="py-10 text-center text-sm text-muted-foreground">Nenhuma conta encontrada com esses filtros.</p>
    </Card>

    <Card class="hidden py-0 sm:flex">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead class="w-10">
              <input type="checkbox" class="size-4 align-middle accent-primary" :checked="todosSelecionados" :disabled="!abertos.length" aria-label="Selecionar todas em aberto" @change="alternarTodos" />
            </TableHead>
            <TableHead>Descrição</TableHead>
            <TableHead class="hidden xl:table-cell">Categoria</TableHead>
            <TableHead class="hidden xl:table-cell">{{ ehReceita ? 'Cliente' : 'Fornecedor' }}</TableHead>
            <TableHead>Vencimento</TableHead>
            <TableHead class="text-right">Valor</TableHead>
            <TableHead class="hidden text-right lg:table-cell">{{ ehReceita ? 'Recebido' : 'Pago' }}</TableHead>
            <TableHead>Status</TableHead>
            <TableHead class="w-28"><span class="sr-only">Ações</span></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="l in itens" :key="l.id" class="cursor-pointer" :class="corLinha(l)" tabindex="0" @click="detalheId = l.id" @keydown.enter="detalheId = l.id">
            <TableCell @click.stop>
              <input v-if="emAberto(l)" v-model="selecionados" type="checkbox" :value="l.id" class="size-4 align-middle accent-primary" :aria-label="`Selecionar ${l.descricao}`" @keydown.enter.stop />
            </TableCell>
            <TableCell class="max-w-60">
              <p class="truncate font-medium">{{ l.descricao }}</p>
              <p class="truncate text-xs text-muted-foreground">
                <template v-if="l.situacao === 'PAGO'">{{ ehReceita ? 'Recebido' : 'Pago' }} em {{ dataBr(l.data_pagamento) }} · {{ l.conta }}<template v-if="l.forma_pagamento_label"> · {{ l.forma_pagamento_label }}</template></template>
                <template v-else>
                  <span class="xl:hidden">{{ l.pessoa ? `${l.pessoa} · ` : '' }}{{ l.categoria }}</span>
                  <template v-if="l.lote"><span class="xl:hidden"> · </span>{{ l.lote }}</template>
                </template>
              </p>
            </TableCell>
            <TableCell class="hidden max-w-36 truncate xl:table-cell">{{ l.categoria }}</TableCell>
            <TableCell class="hidden max-w-36 truncate xl:table-cell">{{ l.pessoa ?? '—' }}</TableCell>
            <TableCell class="tabular-nums">
              {{ dataBr(l.data_vencimento) }}
              <p v-if="l.situacao === 'VENCIDO'" class="text-xs text-red-700 dark:text-red-400">{{ diasAtraso(l.data_vencimento) }} dia(s) em atraso</p>
            </TableCell>
            <TableCell class="text-right font-medium tabular-nums">{{ moeda(l.valor) }}</TableCell>
            <TableCell class="hidden text-right tabular-nums lg:table-cell">{{ moeda(l.valor_pago ?? 0) }}</TableCell>
            <TableCell><SeloSituacao :situacao="l.situacao" :tipo="l.tipo" /></TableCell>
            <TableCell class="px-1 text-right">
              <Button v-if="emAberto(l)" size="icon-sm" variant="outline" class="border-emerald-600 text-emerald-700 dark:text-emerald-400" :title="ehReceita ? 'Receber' : 'Pagar'" :aria-label="`${ehReceita ? 'Receber' : 'Pagar'} ${l.descricao}`"
                @click.stop="baixar([l])" @keydown.enter.stop><CircleCheck /></Button>
              <Button size="icon-sm" variant="ghost" title="Editar" :aria-label="`Editar ${l.descricao}`" @click.stop="abrirForm(l)" @keydown.enter.stop><Pencil /></Button>
              <!-- Pago precisa ser estornado antes de cancelar (a API recusa): o estorno fica na ficha da conta. -->
              <Button v-if="emAberto(l)" size="icon-sm" variant="ghost" class="text-destructive" title="Cancelar" :aria-label="`Cancelar ${l.descricao}`"
                @click.stop="cancelar(l)" @keydown.enter.stop><Ban /></Button>
            </TableCell>
          </TableRow>
          <TableRow v-if="!itens.length && !carregando">
            <TableCell colspan="9" class="py-10 text-center text-muted-foreground">Nenhuma conta encontrada com esses filtros.</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </Card>

    <p v-if="meta && meta.total > itens.length" class="text-center text-xs text-muted-foreground">
      Mostrando as primeiras {{ itens.length }} de {{ meta.total }} contas. Reduza o período para ver as demais.
    </p>
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
  <DialogoCancelamento v-model:open="cancelamentoAberto" :lancamento="paraCancelar" @cancelado="carregar" />
  <FormLancamento v-model:open="formAberto" :lancamento="emEdicao" :padrao="{ tipo: filtros.tipo, data_vencimento: hoje() }" @salvo="carregar" />
  <DetalheLancamento :id="detalheId" @fechar="detalheId = null" @alterado="carregar" />
</template>
