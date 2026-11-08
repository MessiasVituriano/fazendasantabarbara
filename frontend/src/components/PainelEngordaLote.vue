<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { CircleCheck, CircleHelp, CircleX, Flag, LogOut, Plus, Search, Target, TriangleAlert, TrendingDown, TrendingUp, Wheat } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import http from '../api/http'
import AlertaErros from './AlertaErros.vue'
import GraficoPeso from './GraficoPeso.vue'
import { useEnvio } from '../composables/useEnvio'
import { dataBr, hoje, moeda, numero, valorParaApi } from '../formatos'

/**
 * Engorda do lote: animais, peso médio e GMD, meta com a situação calculada, gráfico com projeção
 * e ração/suplementos que saíram do estoque para o lote.
 */
const props = defineProps({ lote: { type: Object, required: true } })
const emit = defineEmits(['alterado'])

const dados = ref(null)
const erroCarga = ref([])
const carregando = ref(false)
async function carregar() {
  carregando.value = true
  try {
    dados.value = (await http.get(`/lotes/${props.lote.id}/engorda`)).data.data
    erroCarga.value = []
  } catch (e) { erroCarga.value = e.mensagens } finally { carregando.value = false }
}
watch(() => props.lote.id, carregar, { immediate: true })
function atualizado(resposta) {
  if (resposta?.data?.data?.resumo) dados.value = resposta.data.data
  else carregar()
  emit('alterado')
}

const ativo = computed(() => !props.lote.data_encerramento)
const r = computed(() => dados.value?.resumo)
const meta = computed(() => dados.value?.meta)
const av = computed(() => meta.value?.avaliacao)

/** Situação da meta: ícone + texto, nunca só cor. */
const SITUACAO = {
  NO_RITMO: { icone: CircleCheck, classe: 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300' },
  ATENCAO: { icone: TriangleAlert, classe: 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300' },
  ATRASADA: { icone: TrendingDown, classe: 'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300' },
  INVIAVEL: { icone: CircleX, classe: 'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300' },
  VENCIDA: { icone: CircleX, classe: 'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300' },
  ATINGIDA: { icone: Flag, classe: 'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-300' },
  SEM_DADOS: { icone: CircleHelp, classe: 'border-border bg-muted text-muted-foreground' },
}

const grafico = computed(() => {
  if (!dados.value?.serie.length) return null
  return {
    rotulo: 'Peso médio',
    pontos: dados.value.serie.map((s) => ({ data: s.data, peso: s.peso_medio, detalhe: `${s.animais} animal(is) pesado(s)` })),
    projecao: r.value.projecoes.length ? [{ data: hoje(), peso: r.value.peso_medio }, ...r.value.projecoes] : [],
    alvo: meta.value ? { peso: meta.value.peso_alvo_kg, data: meta.value.data_alvo, rotulo: 'Meta' } : null,
  }
})

// ---------- Meta ----------
const { enviando, mensagens, campos, enviar } = useEnvio()
const metaAberta = ref(false)
const formMeta = reactive({})
function abrirMeta() {
  mensagens.value = []
  campos.value = {}
  Object.assign(formMeta, { peso_alvo_kg: meta.value ? String(meta.value.peso_alvo_kg).replace('.', ',') : '', data_alvo: meta.value?.data_alvo ?? '', gmd_alvo: '', observacoes: '' })
  metaAberta.value = true
}
/** GMD que a meta exige a partir do peso médio de hoje (prévia no formulário). */
const gmdExigido = computed(() => {
  const alvo = Number(valorParaApi(formMeta.peso_alvo_kg))
  if (!alvo || !formMeta.data_alvo || r.value?.peso_medio == null) return null
  const dias = Math.round((new Date(`${formMeta.data_alvo}T12:00:00`) - new Date(`${hoje()}T12:00:00`)) / 86400000)
  return dias > 0 ? (alvo - r.value.peso_medio) / dias : null
})
async function salvarMeta() {
  try {
    await enviar(() => http.post(`/lotes/${props.lote.id}/metas`, {
      peso_alvo_kg: valorParaApi(formMeta.peso_alvo_kg), data_alvo: formMeta.data_alvo,
      gmd_alvo: valorParaApi(formMeta.gmd_alvo), observacoes: formMeta.observacoes || null,
    }))
    toast.success(meta.value ? 'Meta substituída' : 'Meta definida')
    metaAberta.value = false
    atualizado()
  } catch { /* no formulário */ }
}
async function cancelarMeta() {
  try {
    await http.post(`/lotes/${props.lote.id}/metas/${meta.value.id}/cancelamento`, { motivo: 'Cancelada pelo gestor' })
    toast.success('Meta cancelada')
    atualizado()
  } catch (e) { toast.error(e.mensagens?.[0] ?? 'Não foi possível cancelar a meta') }
}

// ---------- Animais ----------
const addAberto = ref(false)
const candidatos = ref([])
const selecionados = ref(new Set())
const busca = ref('')
const dataEntrada = ref(hoje())
async function abrirAdicionar() {
  mensagens.value = []
  selecionados.value = new Set()
  busca.value = ''
  dataEntrada.value = hoje()
  addAberto.value = true
  try { candidatos.value = (await http.get('/animais', { params: { aptidao: ['CORTE', 'MISTA'], por_pagina: 200 } })).data.data } catch (e) { mensagens.value = e.mensagens }
}
const noLote = computed(() => new Set((dados.value?.animais ?? []).map((a) => a.id)))
const visiveis = computed(() => {
  const q = busca.value.trim().toLowerCase()
  return candidatos.value.filter((a) => !noLote.value.has(a.id) && (!q || `${a.brinco} ${a.nome ?? ''} ${a.categoria_label}`.toLowerCase().includes(q)))
})
function alternar(id) {
  const s = new Set(selecionados.value)
  s.has(id) ? s.delete(id) : s.add(id)
  selecionados.value = s
}
async function adicionar() {
  try {
    const resposta = await enviar(() => http.post(`/lotes/${props.lote.id}/animais`, { animal_ids: [...selecionados.value], data_entrada: dataEntrada.value }))
    toast.success(`${selecionados.value.size} animal(is) no lote`)
    addAberto.value = false
    atualizado(resposta)
  } catch { /* no diálogo */ }
}
async function retirar(a) {
  try {
    const resposta = await http.post(`/lotes/${props.lote.id}/saidas`, { animal_ids: [a.id], data_saida: hoje() })
    toast.success(`${a.nome || a.brinco} saiu do lote`)
    atualizado(resposta)
  } catch (e) { toast.error(e.mensagens?.[0] ?? 'Não foi possível retirar o animal') }
}

const qtd = (v) => Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 3 })
</script>

<template>
  <section class="space-y-4">
    <AlertaErros :mensagens="erroCarga" />
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="text-sm font-medium">Engorda</h3>
      <div v-if="ativo" class="flex gap-2">
        <Button size="sm" variant="outline" @click="abrirAdicionar"><Plus /> Animais</Button>
        <Button size="sm" variant="outline" :disabled="!r?.animais" @click="abrirMeta"><Target /> {{ meta ? 'Nova meta' : 'Definir meta' }}</Button>
      </div>
    </div>

    <template v-if="dados">
      <!-- indicadores -->
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-4" :class="{ 'opacity-50': carregando }">
        <div class="rounded-lg border p-3">
          <p class="text-xs text-muted-foreground">Animais</p>
          <p class="text-lg font-semibold tabular-nums">{{ r.animais }}</p>
          <p v-if="r.sem_pesagem_recente" class="text-xs text-amber-700 dark:text-amber-400">{{ r.sem_pesagem_recente }} sem pesagem recente</p>
        </div>
        <div class="rounded-lg border p-3">
          <p class="text-xs text-muted-foreground">Peso médio hoje</p>
          <p class="text-lg font-semibold tabular-nums">{{ numero(r.peso_medio, 1) }} <span class="text-xs font-normal text-muted-foreground">kg</span></p>
          <p v-if="r.desvio_padrao" class="text-xs text-muted-foreground">± {{ numero(r.desvio_padrao, 0) }} kg · {{ r.abaixo_da_media }} bem abaixo</p>
        </div>
        <div class="rounded-lg border p-3">
          <p class="text-xs text-muted-foreground">GMD médio</p>
          <p class="text-lg font-semibold tabular-nums">{{ numero(r.gmd_medio, 3) }} <span class="text-xs font-normal text-muted-foreground">kg/dia</span></p>
          <p class="text-xs text-muted-foreground">{{ r.com_tendencia }} de {{ r.animais }} com tendência</p>
        </div>
        <div class="rounded-lg border p-3">
          <p class="text-xs text-muted-foreground">Em 90 dias</p>
          <p class="text-lg font-semibold tabular-nums">{{ r.projecoes.length ? numero(r.projecoes.at(-1).peso, 0) : '—' }} <span class="text-xs font-normal text-muted-foreground">kg</span></p>
          <p class="text-xs text-muted-foreground">{{ r.projecoes.length ? dataBr(r.projecoes.at(-1).data) : 'sem projeção' }}</p>
        </div>
      </div>

      <!-- meta -->
      <div v-if="meta" class="space-y-3 rounded-lg border p-3">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="text-xs text-muted-foreground">Meta</p>
            <p class="font-semibold">{{ numero(meta.peso_alvo_kg, 0) }} kg até {{ dataBr(meta.data_alvo) }}</p>
          </div>
          <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium" :class="SITUACAO[av.situacao].classe">
            <component :is="SITUACAO[av.situacao].icone" class="size-3.5" /> {{ av.situacao_label }}
          </span>
        </div>
        <div v-if="av.progresso !== null" class="space-y-1">
          <div class="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="Math.round(av.progresso * 100)" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-primary" :style="{ width: `${av.progresso * 100}%` }" />
          </div>
          <p class="text-xs text-muted-foreground tabular-nums">{{ numero(meta.peso_inicial_kg, 0) }} kg → {{ numero(av.peso_atual, 0) }} kg ({{ numero(av.progresso * 100, 0) }}% do ganho)</p>
        </div>
        <dl class="grid gap-2 text-sm sm:grid-cols-3">
          <div><dt class="text-xs text-muted-foreground">GMD necessário</dt><dd class="font-medium tabular-nums">{{ av.gmd_necessario !== null ? `${numero(av.gmd_necessario, 3)} kg/dia` : '—' }}</dd></div>
          <div><dt class="text-xs text-muted-foreground">GMD real</dt><dd class="font-medium tabular-nums">{{ av.gmd_real !== null ? `${numero(av.gmd_real, 3)} kg/dia` : '—' }}<span v-if="av.ritmo" class="ml-1 text-xs text-muted-foreground">({{ numero(av.ritmo * 100, 0) }}%)</span></dd></div>
          <div>
            <dt class="text-xs text-muted-foreground">Previsão no ritmo atual</dt>
            <dd class="font-medium tabular-nums">
              {{ av.data_projetada ? dataBr(av.data_projetada) : '—' }}
              <span v-if="av.diferenca_dias" class="text-xs" :class="av.diferenca_dias > 0 ? 'text-destructive' : 'text-emerald-700 dark:text-emerald-400'">
                ({{ av.diferenca_dias > 0 ? `${av.diferenca_dias} dias de atraso` : `${-av.diferenca_dias} dias antes` }})
              </span>
            </dd>
          </div>
        </dl>
        <Button v-if="ativo" size="sm" variant="ghost" class="text-destructive" @click="cancelarMeta">Cancelar meta</Button>
      </div>
      <p v-else-if="r.animais" class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">Sem meta de engorda. Defina o peso médio alvo e a data para acompanhar o ritmo do lote.</p>

      <GraficoPeso v-if="grafico" v-bind="grafico" />

      <!-- animais -->
      <div v-if="dados.animais.length" class="overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Animal</TableHead><TableHead class="text-right">Peso</TableHead><TableHead class="text-right">GMD</TableHead>
              <TableHead>{{ meta ? 'Chega na meta' : 'Tendência' }}</TableHead><TableHead v-if="ativo" />
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="a in dados.animais" :key="a.id" :class="{ 'bg-destructive/5': a.abaixo_do_ritmo }">
              <TableCell>
                <RouterLink :to="`/animais/${a.id}`" class="font-medium hover:underline">{{ a.nome || a.brinco }}</RouterLink>
                <p class="text-xs text-muted-foreground"><span class="font-mono">{{ a.brinco }}</span> · {{ a.categoria_label }}</p>
              </TableCell>
              <TableCell class="text-right tabular-nums">{{ numero(a.peso_atual, 0) }}<span class="text-xs text-muted-foreground"> kg</span></TableCell>
              <TableCell class="text-right tabular-nums whitespace-nowrap">
                <span v-if="a.gmd !== null" class="inline-flex items-center gap-1" :class="a.gmd > 0 ? '' : 'text-destructive'">
                  <component :is="a.gmd > 0 ? TrendingUp : TrendingDown" class="size-3.5" />{{ numero(a.gmd, 3) }}
                </span>
                <span v-else class="text-xs text-muted-foreground">—</span>
              </TableCell>
              <TableCell class="text-sm">
                <template v-if="meta">
                  <span v-if="a.previsao_alvo?.dias === 0">Já atingiu</span>
                  <span v-else-if="a.previsao_alvo" :class="{ 'text-destructive': a.previsao_alvo.data > meta.data_alvo }">{{ dataBr(a.previsao_alvo.data) }}</span>
                  <span v-else class="text-muted-foreground">{{ a.situacao_tendencia_label }}</span>
                </template>
                <span v-else class="text-muted-foreground">{{ a.situacao_tendencia_label }}</span>
                <p v-if="a.abaixo_do_ritmo" class="text-xs font-medium text-destructive">Abaixo do ritmo da meta</p>
                <p v-if="a.suspeitas" class="flex items-center gap-1 text-xs text-destructive"><TriangleAlert class="size-3" /> {{ a.suspeitas }} pesagem suspeita</p>
              </TableCell>
              <TableCell v-if="ativo" class="text-right">
                <Button variant="ghost" size="icon" :aria-label="`Retirar ${a.brinco} do lote`" title="Retirar do lote" @click="retirar(a)"><LogOut /></Button>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
      <p v-else class="text-sm text-muted-foreground">Nenhum animal no lote. Adicione os animais para acompanhar peso, GMD e meta.</p>

      <!-- ração -->
      <div class="space-y-3">
        <h3 class="flex items-center gap-2 text-sm font-medium"><Wheat class="size-4" /> Ração e suplementos do lote</h3>
        <template v-if="dados.racao.saidas">
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Últimos 30 dias</p><p class="font-semibold tabular-nums">{{ moeda(dados.racao.custo_30_dias) }}</p></div>
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Por cabeça/dia</p><p class="font-semibold tabular-nums">{{ moeda(dados.racao.custo_cabeca_dia) }}</p></div>
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Por kg ganho*</p><p class="font-semibold tabular-nums">{{ moeda(dados.racao.custo_kg_ganho) }}</p></div>
            <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Desde a formação</p><p class="font-semibold tabular-nums">{{ moeda(dados.racao.custo_total) }}</p></div>
          </div>
          <div class="overflow-x-auto rounded-md border">
            <Table>
              <TableHeader><TableRow><TableHead>Item</TableHead><TableHead class="text-right">30 dias</TableHead><TableHead class="text-right">Total</TableHead><TableHead class="text-right">Custo</TableHead></TableRow></TableHeader>
              <TableBody>
                <TableRow v-for="i in dados.racao.itens" :key="i.item_estoque_id">
                  <TableCell class="font-medium">{{ i.nome }}</TableCell>
                  <TableCell class="text-right tabular-nums">{{ qtd(i.quantidade_30_dias) }} {{ i.unidade_sigla }}</TableCell>
                  <TableCell class="text-right tabular-nums">{{ qtd(i.quantidade) }} {{ i.unidade_sigla }}</TableCell>
                  <TableCell class="text-right tabular-nums">{{ moeda(i.custo) }}<p v-if="i.sem_custo" class="text-xs text-muted-foreground">parte sem preço</p></TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>
          <p class="text-xs text-muted-foreground">* Custo de ração dos últimos 30 dias dividido pelo ganho estimado no período (animais × GMD médio × 30). Custo pelo preço do item no dia de cada saída.</p>
        </template>
        <p v-else class="text-sm text-muted-foreground">Nenhuma saída de estoque para este lote. Na tela Estoque, registre a saída escolhendo o lote de destino.</p>
      </div>
    </template>
  </section>

  <!-- Nova meta -->
  <Dialog v-model:open="metaAberta">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ meta ? 'Nova meta de engorda' : 'Definir meta de engorda' }}</DialogTitle>
        <DialogDescription>Peso médio do lote a atingir até a data. {{ meta ? 'A meta atual fica no histórico como substituída.' : '' }}</DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="Object.keys(campos).length ? [] : mensagens" />
      <form id="form-meta" class="grid gap-4 sm:grid-cols-2" @submit.prevent="salvarMeta">
        <div class="grid gap-1.5">
          <Label for="meta-peso">Peso médio alvo (kg)</Label>
          <Input id="meta-peso" v-model="formMeta.peso_alvo_kg" inputmode="decimal" required :aria-invalid="!!campos.peso_alvo_kg" />
          <p v-if="campos.peso_alvo_kg" class="text-xs text-destructive">{{ campos.peso_alvo_kg }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="meta-data">Data alvo</Label>
          <Input id="meta-data" v-model="formMeta.data_alvo" type="date" :min="hoje()" required :aria-invalid="!!campos.data_alvo" />
          <p v-if="campos.data_alvo" class="text-xs text-destructive">{{ campos.data_alvo }}</p>
        </div>
        <p v-if="gmdExigido !== null" class="rounded-md bg-muted px-3 py-2 text-xs sm:col-span-2">
          A partir de {{ numero(r.peso_medio, 0) }} kg hoje, o lote precisa ganhar <b class="tabular-nums">{{ numero(gmdExigido, 3) }} kg/dia</b>
          <template v-if="r.gmd_medio !== null"> (hoje ganha {{ numero(r.gmd_medio, 3) }} kg/dia).</template>
        </p>
        <div class="grid gap-1.5 sm:col-span-2">
          <Label for="meta-obs">Observações</Label>
          <Textarea id="meta-obs" v-model="formMeta.observacoes" rows="2" placeholder="Ex.: venda prevista para o frigorífico" />
        </div>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="metaAberta = false">Cancelar</Button>
        <Button type="submit" form="form-meta" :disabled="enviando">Salvar meta</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- Adicionar animais -->
  <Dialog v-model:open="addAberto">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>Adicionar animais ao lote</DialogTitle>
        <DialogDescription>Animais de corte e mista. Quem está em outro lote é transferido na data de entrada.</DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />
      <div class="grid gap-3 sm:grid-cols-[1fr_10rem]">
        <div class="relative">
          <Search class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input v-model="busca" placeholder="Buscar brinco, nome ou categoria" class="pl-8" />
        </div>
        <Input v-model="dataEntrada" type="date" :max="hoje()" :min="lote.data_formacao" aria-label="Data de entrada" />
      </div>
      <ul class="max-h-72 divide-y overflow-y-auto rounded-md border">
        <li v-for="a in visiveis" :key="a.id">
          <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-muted/50">
            <input type="checkbox" class="size-4 accent-primary" :checked="selecionados.has(a.id)" @change="alternar(a.id)" />
            <span class="min-w-0 flex-1">
              <span class="font-medium">{{ a.nome || a.brinco }}</span>
              <span class="ml-2 font-mono text-xs text-muted-foreground">{{ a.brinco }}</span>
            </span>
            <span class="text-xs text-muted-foreground">{{ a.categoria_label }}</span>
          </label>
        </li>
        <li v-if="!visiveis.length" class="px-3 py-6 text-center text-sm text-muted-foreground">Nenhum animal disponível.</li>
      </ul>
      <DialogFooter>
        <Button variant="ghost" @click="addAberto = false">Cancelar</Button>
        <Button :disabled="enviando || !selecionados.size" @click="adicionar">Adicionar {{ selecionados.size || '' }}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
