<script setup>
import { computed, ref, watch } from 'vue'
import { ChevronLeft, ChevronRight, Landmark, Plus, Wallet } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import http from '../../api/http'
import AlertaErros from '../../components/AlertaErros.vue'
import DetalheLancamento from '../../components/financeiro/DetalheLancamento.vue'
import FormLancamento from '../../components/financeiro/FormLancamento.vue'
import GraficoReceitasDespesas from '../../components/financeiro/GraficoReceitasDespesas.vue'
import ValorTipo from '../../components/financeiro/ValorTipo.vue'
import CabecalhoPagina from '../../components/layout/CabecalhoPagina.vue'
import { dataBr, hoje, inicioDoMes, mesCurto, moeda } from '../../formatos'

/**
 * Visão geral do financeiro: dinheiro disponível, mês (realizado × competência),
 * contas a pagar/receber, próximos vencimentos, despesas por categoria e evolução de 12 meses.
 */
const referencia = ref(inicioDoMes())
const dados = ref(null)
const carregando = ref(false)
const erros = ref([])

async function carregar() {
  carregando.value = true
  try {
    dados.value = (await http.get('/financeiro/resumo', { params: { referencia: referencia.value } })).data.data
    erros.value = []
  } catch (e) {
    erros.value = e.mensagens
  } finally {
    carregando.value = false
  }
}
watch(referencia, carregar, { immediate: true })

const mudarMes = (n) => (referencia.value = inicioDoMes(referencia.value, n))
const ehMesAtual = computed(() => referencia.value === inicioDoMes())
const rotuloMes = computed(() => mesCurto(referencia.value.slice(0, 7)))

const semContas = computed(() => dados.value && !dados.value.saldo.contas.length)
const maiorCategoria = computed(() => Math.max(1, ...(dados.value?.despesas_por_categoria ?? []).map((c) => c.total)))
const vencido = (l) => l.situacao === 'VENCIDO'

const novo = ref(false)
const detalhe = ref(null)
const corResultado = (v) => (v < 0 ? 'text-red-700 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-400')

const AGENDA = [
  ['vencido', 'Atrasado'],
  ['hoje', 'Vence hoje'],
  ['proximos_7_dias', 'Próximos 7 dias'],
  ['proximos_30_dias', '8 a 30 dias'],
]
</script>

<template>
  <CabecalhoPagina titulo="Financeiro">
    <template #acoes>
      <div class="flex items-center gap-1 rounded-md border">
        <Button variant="ghost" size="icon" class="size-8" aria-label="Mês anterior" @click="mudarMes(-1)"><ChevronLeft /></Button>
        <span class="min-w-16 text-center text-sm font-medium capitalize">{{ rotuloMes }}</span>
        <Button variant="ghost" size="icon" class="size-8" aria-label="Próximo mês" :disabled="ehMesAtual" @click="mudarMes(1)"><ChevronRight /></Button>
      </div>
      <Button @click="novo = true"><Plus /> <span class="hidden sm:inline">Novo lançamento</span></Button>
    </template>
  </CabecalhoPagina>

  <main class="space-y-6 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erros" />

    <div v-if="!dados && carregando" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Skeleton v-for="i in 4" :key="i" class="h-32 rounded-xl" />
    </div>

    <Card v-else-if="semContas" class="border-dashed">
      <CardContent class="flex flex-col items-center gap-3 py-10 text-center">
        <Landmark class="size-10 text-muted-foreground" />
        <p class="text-lg font-medium">Cadastre o caixa e as contas bancárias</p>
        <p class="max-w-md text-sm text-muted-foreground">
          Informe o saldo de cada conta numa data de partida. A partir daí o sistema calcula o dinheiro disponível com os pagamentos e recebimentos.
        </p>
        <Button as-child><RouterLink to="/financeiro/cadastros">Cadastrar contas</RouterLink></Button>
      </CardContent>
    </Card>

    <template v-if="dados">
      <!-- Dinheiro disponível -->
      <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <Card class="md:col-span-2 xl:col-span-1">
          <CardHeader>
            <CardDescription class="flex items-center gap-1.5"><Wallet class="size-4" /> Dinheiro disponível hoje</CardDescription>
            <CardTitle class="text-3xl tabular-nums" :class="dados.saldo.total < 0 ? 'text-red-700 dark:text-red-400' : ''">{{ moeda(dados.saldo.total) }}</CardTitle>
          </CardHeader>
          <CardContent>
            <ul class="divide-y text-sm">
              <li v-for="c in dados.saldo.contas" :key="c.id" class="flex justify-between gap-2 py-1.5">
                <span class="truncate" :class="{ 'text-muted-foreground': !c.ativa }">{{ c.nome }}<span v-if="!c.ativa"> (inativa)</span></span>
                <span class="tabular-nums" :class="c.saldo < 0 ? 'text-red-700 dark:text-red-400' : ''">{{ moeda(c.saldo) }}</span>
              </li>
            </ul>
            <p class="mt-2 text-xs text-muted-foreground">Somente o que já foi pago ou recebido. Contas em aberto não alteram o saldo.</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardDescription>Realizado em <span class="capitalize">{{ rotuloMes }}</span> · caixa</CardDescription>
            <CardTitle class="text-2xl tabular-nums" :class="corResultado(dados.mes_caixa.resultado)">{{ moeda(dados.mes_caixa.resultado) }}</CardTitle>
          </CardHeader>
          <CardContent class="space-y-1 text-sm">
            <div class="flex justify-between"><span class="text-muted-foreground">Recebido</span><ValorTipo :valor="dados.mes_caixa.receitas" tipo="RECEITA" /></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Pago</span><ValorTipo :valor="dados.mes_caixa.despesas" tipo="DESPESA" /></div>
            <p class="pt-1 text-xs text-muted-foreground">Pela data em que o dinheiro entrou ou saiu.</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardDescription>Resultado de <span class="capitalize">{{ rotuloMes }}</span> · competência</CardDescription>
            <CardTitle class="text-2xl tabular-nums" :class="corResultado(dados.mes_competencia.resultado)">{{ moeda(dados.mes_competencia.resultado) }}</CardTitle>
          </CardHeader>
          <CardContent class="space-y-1 text-sm">
            <div class="flex justify-between"><span class="text-muted-foreground">Receitas</span><ValorTipo :valor="dados.mes_competencia.receitas" tipo="RECEITA" /></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Despesas</span><ValorTipo :valor="dados.mes_competencia.despesas" tipo="DESPESA" /></div>
            <p class="pt-1 text-xs text-muted-foreground">O que foi gerado no mês, pago ou não.</p>
          </CardContent>
        </Card>
      </section>

      <!-- A pagar / a receber -->
      <section class="grid gap-4 md:grid-cols-2">
        <Card v-for="lado in [['a_pagar', 'A pagar', 'DESPESA'], ['a_receber', 'A receber', 'RECEITA']]" :key="lado[0]">
          <CardHeader>
            <div class="flex items-start justify-between gap-2">
              <div>
                <CardDescription>{{ lado[1] }} · em aberto</CardDescription>
                <CardTitle class="text-2xl tabular-nums">{{ moeda(dados[lado[0]].total.valor) }}</CardTitle>
              </div>
              <Button variant="link" size="sm" as-child class="px-0"><RouterLink to="/financeiro/contas-a-pagar-e-receber">Ver contas</RouterLink></Button>
            </div>
          </CardHeader>
          <CardContent class="@container">
            <dl class="grid grid-cols-2 gap-3 text-sm @lg:grid-cols-4">
              <div v-for="f in AGENDA" :key="f[0]" class="rounded-md p-2" :class="f[0] === 'vencido' && dados[lado[0]].vencido.quantidade ? 'bg-red-50 dark:bg-red-950/50' : 'bg-muted/50'">
                <dt class="text-xs" :class="f[0] === 'vencido' && dados[lado[0]].vencido.quantidade ? 'font-medium text-red-700 dark:text-red-400' : 'text-muted-foreground'">{{ f[1] }}</dt>
                <dd class="font-medium tabular-nums">{{ moeda(dados[lado[0]][f[0]].valor) }}</dd>
                <dd class="text-xs text-muted-foreground">{{ dados[lado[0]][f[0]].quantidade }} conta(s)</dd>
              </div>
            </dl>
          </CardContent>
        </Card>
      </section>

      <section class="grid grid-cols-1 gap-4 xl:grid-cols-5">
        <Card class="xl:col-span-3">
          <CardHeader>
            <CardTitle class="text-base">Recebido × pago — últimos 12 meses</CardTitle>
            <CardDescription>Valores realizados, pela data de pagamento.</CardDescription>
          </CardHeader>
          <CardContent><GraficoReceitasDespesas :serie="dados.serie_mensal" rotulo-a="Recebido" rotulo-b="Pago" descricao="Recebido e pago por mês" /></CardContent>
        </Card>

        <Card class="xl:col-span-2">
          <CardHeader>
            <CardTitle class="text-base">Próximos vencimentos</CardTitle>
            <CardDescription>Contas em aberto, das mais antigas para as mais novas.</CardDescription>
          </CardHeader>
          <CardContent>
            <ul v-if="dados.proximos_vencimentos.length" class="divide-y">
              <li v-for="l in dados.proximos_vencimentos" :key="l.id">
                <button class="flex w-full items-center gap-3 rounded-md px-1 py-2 text-left text-sm hover:bg-accent" @click="detalhe = l.id">
                  <span class="w-12 shrink-0 text-xs tabular-nums" :class="vencido(l) ? 'font-semibold text-red-700 dark:text-red-400' : 'text-muted-foreground'">{{ dataBr(l.data_vencimento).slice(0, 5) }}</span>
                  <span class="min-w-0 flex-1">
                    <span class="block truncate">{{ l.descricao }}</span>
                    <span class="block truncate text-xs text-muted-foreground">{{ l.pessoa ?? l.categoria }}<template v-if="vencido(l)"> · atrasado</template></span>
                  </span>
                  <ValorTipo :valor="l.valor" :tipo="l.tipo" class="text-sm" />
                </button>
              </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">Nenhuma conta em aberto.</p>
          </CardContent>
        </Card>
      </section>

      <section class="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Despesas de <span class="capitalize">{{ rotuloMes }}</span> por categoria</CardTitle>
            <CardDescription>Por competência (pagas ou não).</CardDescription>
          </CardHeader>
          <CardContent>
            <ul v-if="dados.despesas_por_categoria.length" class="space-y-2.5">
              <li v-for="c in dados.despesas_por_categoria" :key="c.categoria_id" class="text-sm">
                <div class="mb-1 flex justify-between gap-2"><span class="truncate">{{ c.categoria }}</span><span class="tabular-nums">{{ moeda(c.total) }}</span></div>
                <div class="h-2 rounded-full bg-muted">
                  <div class="h-2 rounded-full" :style="{ width: `${(c.total / maiorCategoria) * 100}%`, background: 'var(--chart-2)' }" />
                </div>
              </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">Nenhuma despesa neste mês.</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle class="text-base">Quanto já foi investido</CardTitle>
            <CardDescription>Total realizado (pago/recebido) desde o início dos registros.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-3 text-sm">
            <div class="flex justify-between"><span>Compra de animais (aquisição)</span><span class="font-medium tabular-nums">{{ moeda(dados.acumulado.aquisicao_animais) }}</span></div>
            <div class="flex justify-between"><span>Custo operacional</span><span class="font-medium tabular-nums">{{ moeda(dados.acumulado.custo_operacional) }}</span></div>
            <div class="flex justify-between border-t pt-2"><span class="font-medium">Total pago</span><span class="font-semibold tabular-nums">{{ moeda(dados.acumulado.despesas) }}</span></div>
            <div class="flex justify-between"><span>Total recebido</span><ValorTipo :valor="dados.acumulado.receitas" tipo="RECEITA" /></div>
            <div class="flex justify-between border-t pt-2">
              <span class="font-medium">Recebido − pago</span>
              <span class="font-semibold tabular-nums" :class="corResultado(dados.acumulado.receitas - dados.acumulado.despesas)">{{ moeda(dados.acumulado.receitas - dados.acumulado.despesas) }}</span>
            </div>
            <p class="text-xs text-muted-foreground">Aquisição e custo de manutenção aparecem separados. O valor do rebanho ainda não entra aqui.</p>
          </CardContent>
        </Card>
      </section>
    </template>
  </main>

  <FormLancamento v-model:open="novo" @salvo="carregar" />
  <DetalheLancamento :id="detalhe" @fechar="detalhe = null" @alterado="carregar" />
</template>
