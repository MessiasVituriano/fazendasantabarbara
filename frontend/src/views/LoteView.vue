<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeft, Info, Pencil, Plus } from '@lucide/vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import FormLancamento from '../components/financeiro/FormLancamento.vue'
import FormLote from '../components/FormLote.vue'
import PainelEngordaLote from '../components/PainelEngordaLote.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { dataBr, mesCurto, moeda } from '../formatos'

/**
 * Página do lote de recria/engorda: painel de engorda (meta, gráfico com projeção, ração) e custos
 * vindos das despesas do financeiro vinculadas ao lote.
 * Aquisição dos animais e custo operacional da recria aparecem sempre separados.
 */
const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()

const detalhe = ref(null)
const erroDetalhe = ref([])
async function carregar() {
  erroDetalhe.value = []
  try { detalhe.value = (await http.get(`/lotes/${props.id}`)).data.data } catch (e) { erroDetalhe.value = e.mensagens }
}
watch(() => props.id, () => { detalhe.value = null; carregar() }, { immediate: true })

/** Volta para a tela anterior do app; aberto por link direto, vai para a lista de lotes. */
function voltar() {
  if (window.history.state?.back) router.back()
  else router.push('/lotes')
}

const formAberto = ref(false)
const lancarAberto = ref(false)
const titulo = computed(() => detalhe.value?.nome ?? 'Lote')
</script>

<template>
  <CabecalhoPagina :titulo="titulo">
    <template #antes-titulo>
      <Button variant="ghost" size="sm" class="-ml-2 md:-ml-3" @click="voltar"><ArrowLeft /> <span class="hidden sm:inline">Voltar</span></Button>
    </template>
    <template v-if="detalhe" #acoes>
      <Badge v-if="detalhe.data_encerramento" variant="outline">Encerrado em {{ dataBr(detalhe.data_encerramento) }}</Badge>
      <Badge v-else class="border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">Ativo</Badge>
    </template>
  </CabecalhoPagina>

  <main v-if="!detalhe" class="px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erroDetalhe" />
    <p v-if="!erroDetalhe.length" class="text-sm text-muted-foreground">Carregando…</p>
  </main>
  <main v-if="detalhe" class="space-y-5 px-4 py-6 md:px-6">
    <p class="text-sm text-muted-foreground">
      {{ [detalhe.categoria, detalhe.origem].filter(Boolean).join(' · ') || 'Sem categoria' }} · formado em {{ dataBr(detalhe.data_formacao) }}
      <template v-if="detalhe.data_encerramento"> · encerrado em {{ dataBr(detalhe.data_encerramento) }}</template>
    </p>
    <AlertaErros :mensagens="erroDetalhe" />
    <div class="flex flex-wrap gap-2">
      <Button size="sm" @click="lancarAberto = true"><Plus /> Lançar despesa neste lote</Button>
      <Button size="sm" variant="outline" as-child><RouterLink :to="`/financeiro/lancamentos?lote_id=${detalhe.id}`">Ver lançamentos</RouterLink></Button>
      <Button size="sm" variant="ghost" @click="formAberto = true"><Pencil /> Editar lote</Button>
    </div>
    <p v-if="detalhe.observacoes" class="whitespace-pre-line text-sm text-muted-foreground">{{ detalhe.observacoes }}</p>

    <PainelEngordaLote :lote="detalhe" @alterado="carregar" />

    <template v-if="detalhe.custos">
      <!-- Aquisição x operacional x total -->
      <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-lg border p-3">
          <p class="text-xs text-muted-foreground">Aquisição dos animais</p>
          <p class="text-lg font-semibold tabular-nums">{{ moeda(detalhe.custos.aquisicao) }}</p>
        </div>
        <div class="rounded-lg border p-3">
          <p class="text-xs text-muted-foreground">Custo operacional da recria</p>
          <p class="text-lg font-semibold tabular-nums">{{ moeda(detalhe.custos.operacional) }}</p>
        </div>
        <div class="rounded-lg border bg-muted/50 p-3">
          <p class="text-xs text-muted-foreground">Custo total do lote</p>
          <p class="text-lg font-semibold tabular-nums">{{ moeda(detalhe.custos.total) }}</p>
          <p class="text-xs text-muted-foreground">pago {{ moeda(detalhe.custos.pago) }} · em aberto {{ moeda(detalhe.custos.em_aberto) }}</p>
        </div>
      </div>

      <p class="flex gap-2 rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
        <Info class="mt-0.5 size-3.5 shrink-0" />
        Os custos aqui são por competência: incluem despesas já pagas e em aberto; cancelados não entram.
        A ração que sai do estoque para o lote aparece em Engorda, acima.
      </p>

      <section>
        <h3 class="mb-2 text-sm font-medium">Custos por grupo</h3>
        <div class="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow><TableHead>Grupo</TableHead><TableHead class="text-right">Pago</TableHead><TableHead class="text-right">Em aberto</TableHead><TableHead class="text-right">Total</TableHead></TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="g in detalhe.custos.grupos" :key="g.grupo">
                <TableCell>{{ g.rotulo }} <Badge v-if="!g.operacional" variant="secondary" class="ml-1">aquisição</Badge></TableCell>
                <TableCell class="text-right tabular-nums">{{ moeda(g.pago) }}</TableCell>
                <TableCell class="text-right tabular-nums">{{ moeda(g.em_aberto) }}</TableCell>
                <TableCell class="text-right font-medium tabular-nums">{{ moeda(g.total) }}</TableCell>
              </TableRow>
              <TableRow v-if="!detalhe.custos.grupos.length"><TableCell colspan="4" class="text-muted-foreground">Nenhuma despesa vinculada a este lote.</TableCell></TableRow>
            </TableBody>
            <TableFooter v-if="detalhe.custos.grupos.length">
              <TableRow>
                <TableCell>Total</TableCell>
                <TableCell class="text-right tabular-nums">{{ moeda(detalhe.custos.pago) }}</TableCell>
                <TableCell class="text-right tabular-nums">{{ moeda(detalhe.custos.em_aberto) }}</TableCell>
                <TableCell class="text-right tabular-nums">{{ moeda(detalhe.custos.total) }}</TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        </div>
      </section>

      <section v-if="detalhe.custos.mensal.length">
        <h3 class="mb-2 text-sm font-medium">Custo acumulado por mês</h3>
        <div class="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow><TableHead>Mês</TableHead><TableHead class="text-right">Aquisição</TableHead><TableHead class="text-right">Operacional</TableHead><TableHead class="text-right">Acumulado</TableHead></TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="m in detalhe.custos.mensal" :key="m.mes">
                <TableCell>{{ mesCurto(m.mes) }}</TableCell>
                <TableCell class="text-right tabular-nums">{{ moeda(m.aquisicao) }}</TableCell>
                <TableCell class="text-right tabular-nums">{{ moeda(m.operacional) }}</TableCell>
                <TableCell class="text-right font-medium tabular-nums">{{ moeda(m.acumulado) }}</TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      </section>

      <section>
        <h3 class="mb-2 text-sm font-medium">Receitas e resultado</h3>
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
          <div class="rounded-lg border p-3">
            <dt class="text-xs text-muted-foreground">Receitas do lote</dt>
            <dd class="text-lg font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">{{ moeda(detalhe.custos.receitas.total) }}</dd>
            <dd class="text-xs text-muted-foreground">recebido {{ moeda(detalhe.custos.receitas.pago) }} · a receber {{ moeda(detalhe.custos.receitas.em_aberto) }}</dd>
          </div>
          <div class="rounded-lg border p-3">
            <dt class="text-xs text-muted-foreground">Resultado realizado <span class="font-normal">(recebido − pago)</span></dt>
            <dd class="text-lg font-semibold tabular-nums" :class="detalhe.custos.resultado_realizado < 0 ? 'text-red-700 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-400'">{{ moeda(detalhe.custos.resultado_realizado) }}</dd>
            <dt class="mt-2 text-xs text-muted-foreground">Resultado por competência <span class="font-normal">(inclui valores em aberto)</span></dt>
            <dd class="font-semibold tabular-nums" :class="detalhe.custos.resultado_competencia < 0 ? 'text-red-700 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-400'">{{ moeda(detalhe.custos.resultado_competencia) }}</dd>
          </div>
        </dl>
      </section>
    </template>
  </main>

  <FormLancamento v-if="detalhe" v-model:open="lancarAberto" :padrao="{ tipo: 'DESPESA', lote_id: detalhe.id }" @salvo="carregar" />
  <FormLote v-if="detalhe" v-model:open="formAberto" :lote="detalhe" @salvo="carregar" />
</template>
