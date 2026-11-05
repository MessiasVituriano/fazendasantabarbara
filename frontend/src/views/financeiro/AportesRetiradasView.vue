<script setup>
import { computed, ref, watch } from 'vue'
import { ArrowDownToLine, ArrowUpFromLine, Ban } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import http from '../../api/http'
import AlertaErros from '../../components/AlertaErros.vue'
import CabecalhoPagina from '../../components/layout/CabecalhoPagina.vue'
import { useCadastrosFinanceiros } from '../../composables/useCadastrosFinanceiros'
import { useEnvio } from '../../composables/useEnvio'
import { useOpcoes } from '../../composables/useOpcoes'
import { dataBr, hoje, moeda, valorParaApi } from '../../formatos'

/**
 * Aportes e retiradas de investidores: alteram o saldo das contas e aparecem no fluxo de caixa,
 * mas não são receita nem despesa (ficam fora do resultado e do custo dos lotes).
 */
const TODOS = 'todos'
const NOVO = '_novo'
const { opcoes } = useOpcoes()
const { contasAtivas, pessoas, recarregar } = useCadastrosFinanceiros()
const { enviando, mensagens, campos, enviar } = useEnvio()

const investidores = computed(() => pessoas.value.filter((p) => p.tipo === 'INVESTIDOR'))

// ---------------- Listagem ----------------
const filtros = ref({ pessoa_id: TODOS, tipo: TODOS, inicio: '', fim: '' })
const movimentos = ref([])
const totais = ref(null)
const posicao = ref([])
const erroCarga = ref([])
const carregando = ref(false)

async function carregar() {
  const f = filtros.value
  if (f.inicio && f.fim && f.inicio > f.fim) return
  carregando.value = true
  try {
    const { data } = await http.get('/financeiro/aportes-retiradas', {
      params: {
        pessoa_id: f.pessoa_id === TODOS ? undefined : f.pessoa_id,
        tipo: f.tipo === TODOS ? undefined : f.tipo,
        inicio: f.inicio || undefined,
        fim: f.fim || undefined,
      },
    })
    movimentos.value = data.data
    totais.value = data.meta.totais
    posicao.value = data.meta.investidores
    erroCarga.value = []
  } catch (e) {
    erroCarga.value = e.mensagens
  } finally {
    carregando.value = false
  }
}
watch(filtros, carregar, { immediate: true, deep: true })

const corValor = (v) => (v < 0 ? 'text-red-700 dark:text-red-400' : '')

// ---------------- Registrar ----------------
const formAberto = ref(false)
const form = ref({})
const ehAporte = computed(() => form.value.tipo === 'APORTE')
const investidoresAtivos = computed(() => investidores.value.filter((p) => p.ativo))

function abrir(tipo) {
  mensagens.value = []
  campos.value = {}
  form.value = {
    tipo, pessoa_id: investidoresAtivos.value.length ? null : NOVO, novo_investidor: '', valor: '', data_movimento: hoje(),
    conta_financeira_id: contasAtivas.value.length === 1 ? String(contasAtivas.value[0].id) : null,
    forma_pagamento: 'PIX', documento: '', observacoes: '',
  }
  formAberto.value = true
}

async function salvar() {
  const f = form.value
  try {
    await enviar(async () => {
      if (f.pessoa_id === NOVO) {
        const { data } = await http.post('/pessoas', { nome: f.novo_investidor, tipo: 'INVESTIDOR' })
        await recarregar()
        // Se o movimento falhar abaixo, uma nova tentativa não cadastra o investidor de novo.
        f.pessoa_id = String(data.data.id)
      }
      return http.post('/financeiro/aportes-retiradas', {
        tipo: f.tipo, pessoa_id: Number(f.pessoa_id) || null, valor: valorParaApi(f.valor), data_movimento: f.data_movimento,
        conta_financeira_id: Number(f.conta_financeira_id) || null, forma_pagamento: f.forma_pagamento,
        documento: f.documento || null, observacoes: f.observacoes || null,
      })
    })
    toast.success(ehAporte.value ? 'Aporte registrado' : 'Retirada registrada')
    formAberto.value = false
    carregar()
    recarregar()
  } catch { /* erros exibidos no formulário */ }
}

// ---------------- Cancelar ----------------
const cancelando = ref(null)
const motivo = ref('')
const envioCancelamento = useEnvio()

function abrirCancelamento(m) {
  envioCancelamento.mensagens.value = []
  envioCancelamento.campos.value = {}
  motivo.value = ''
  cancelando.value = m
}

async function confirmarCancelamento() {
  try {
    await envioCancelamento.enviar(() => http.post(`/financeiro/aportes-retiradas/${cancelando.value.id}/cancelamento`, { motivo: motivo.value }))
    toast.success('Movimento cancelado')
    cancelando.value = null
    carregar()
    recarregar()
  } catch { /* erros exibidos no diálogo */ }
}
</script>

<template>
  <CabecalhoPagina titulo="Aportes e retiradas">
    <template #acoes>
      <Button variant="outline" @click="abrir('RETIRADA')"><ArrowUpFromLine /> Registrar retirada</Button>
      <Button @click="abrir('APORTE')"><ArrowDownToLine /> Registrar aporte</Button>
    </template>
    <template #filtros>
      <Select v-model="filtros.pessoa_id">
        <SelectTrigger size="sm" class="w-52" aria-label="Investidor"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem :value="TODOS">Todos os investidores</SelectItem>
          <SelectItem v-for="p in investidores" :key="p.id" :value="String(p.id)">{{ p.nome }}</SelectItem>
        </SelectContent>
      </Select>
      <Select v-model="filtros.tipo">
        <SelectTrigger size="sm" class="w-48" aria-label="Tipo"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem :value="TODOS">Aportes e retiradas</SelectItem>
          <SelectItem value="APORTE">Só aportes</SelectItem>
          <SelectItem value="RETIRADA">Só retiradas</SelectItem>
        </SelectContent>
      </Select>
      <Input v-model="filtros.inicio" type="date" class="h-8 w-38" aria-label="Data inicial" />
      <span class="text-sm text-muted-foreground">a</span>
      <Input v-model="filtros.fim" type="date" :min="filtros.inicio" class="h-8 w-38" aria-label="Data final" />
    </template>
  </CabecalhoPagina>

  <main class="space-y-6 px-4 py-6 md:px-6" :class="{ 'opacity-60': carregando }">
    <AlertaErros :mensagens="erroCarga" />

    <section v-if="totais" class="grid gap-4 sm:grid-cols-3">
      <Card>
        <CardHeader><CardDescription>Aportes</CardDescription>
          <CardTitle class="text-xl tabular-nums text-emerald-700 dark:text-emerald-400">{{ moeda(totais.aportes) }}</CardTitle></CardHeader>
      </Card>
      <Card>
        <CardHeader><CardDescription>Retiradas</CardDescription>
          <CardTitle class="text-xl tabular-nums text-red-700 dark:text-red-400">{{ moeda(totais.retiradas) }}</CardTitle></CardHeader>
      </Card>
      <Card>
        <CardHeader><CardDescription>Aportes menos retiradas</CardDescription>
          <CardTitle class="text-xl tabular-nums" :class="corValor(totais.liquido)">{{ moeda(totais.liquido) }}</CardTitle></CardHeader>
      </Card>
    </section>

    <Card v-if="posicao.length">
      <CardHeader>
        <CardTitle class="text-base">Posição por investidor</CardTitle>
        <CardDescription>Acumulado desde o início, sem considerar os filtros acima.</CardDescription>
      </CardHeader>
      <CardContent class="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Investidor</TableHead><TableHead class="text-right">Aportado</TableHead>
              <TableHead class="text-right">Retirado</TableHead><TableHead class="text-right">Aportes menos retiradas</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="p in posicao" :key="p.pessoa_id" class="tabular-nums">
              <TableCell class="font-medium">{{ p.investidor }} <Badge v-if="!p.ativo" variant="outline" class="ml-1">Inativo</Badge></TableCell>
              <TableCell class="text-right">{{ moeda(p.aportes) }}</TableCell>
              <TableCell class="text-right">{{ moeda(p.retiradas) }}</TableCell>
              <TableCell class="text-right font-medium" :class="corValor(p.liquido)">{{ moeda(p.liquido) }}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </CardContent>
    </Card>

    <div class="overflow-x-auto rounded-xl border bg-card">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Data</TableHead><TableHead>Investidor</TableHead><TableHead>Tipo</TableHead><TableHead>Conta</TableHead>
            <TableHead class="text-right">Valor</TableHead><TableHead />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="m in movimentos" :key="m.id" :class="{ 'opacity-60': m.cancelado }">
            <TableCell class="whitespace-nowrap text-sm">{{ dataBr(m.data_movimento) }}</TableCell>
            <TableCell>
              <p class="font-medium">{{ m.investidor }}</p>
              <p v-if="m.cancelado" class="max-w-xs truncate text-xs text-muted-foreground">Cancelado: {{ m.motivo_cancelamento }}</p>
              <p v-else-if="m.observacoes" class="max-w-xs truncate text-xs text-muted-foreground">{{ m.observacoes }}</p>
            </TableCell>
            <TableCell class="text-sm">{{ m.tipo_label }} <Badge v-if="m.cancelado" variant="outline" class="ml-1">Cancelado</Badge></TableCell>
            <TableCell class="text-sm">
              {{ m.conta }}<template v-if="m.forma_pagamento_label"><br /><span class="text-xs text-muted-foreground">{{ m.forma_pagamento_label }}</span></template>
            </TableCell>
            <TableCell class="text-right font-medium tabular-nums"
              :class="m.cancelado ? 'line-through' : m.tipo === 'APORTE' ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'">
              {{ m.tipo === 'APORTE' ? '+' : '−' }} {{ moeda(m.valor) }}
            </TableCell>
            <TableCell class="text-right">
              <Button v-if="!m.cancelado" variant="ghost" size="icon" :aria-label="`Cancelar ${m.tipo_label.toLowerCase()} de ${m.investidor}`" @click="abrirCancelamento(m)"><Ban /></Button>
            </TableCell>
          </TableRow>
          <TableRow v-if="!movimentos.length"><TableCell colspan="6" class="text-muted-foreground">Nenhum aporte ou retirada encontrado.</TableCell></TableRow>
        </TableBody>
      </Table>
    </div>
  </main>

  <!-- Diálogo: registrar -->
  <Dialog v-model:open="formAberto">
    <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ ehAporte ? 'Registrar aporte' : 'Registrar retirada' }}</DialogTitle>
        <DialogDescription>
          {{ ehAporte ? 'Dinheiro que o investidor colocou na fazenda.' : 'Dinheiro que o investidor tirou da fazenda.' }}
          Altera o saldo da conta, mas não entra como receita nem despesa.
        </DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />

      <form id="form-movimento" class="grid gap-4 sm:grid-cols-2" @submit.prevent="salvar">
        <div class="grid gap-1.5 sm:col-span-2">
          <Label>Investidor</Label>
          <Select v-model="form.pessoa_id">
            <SelectTrigger class="w-full" :aria-invalid="!!campos.pessoa_id"><SelectValue placeholder="Escolha o investidor" /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="p in investidoresAtivos" :key="p.id" :value="String(p.id)">{{ p.nome }}</SelectItem>
              <SelectItem :value="NOVO">+ Novo investidor</SelectItem>
            </SelectContent>
          </Select>
          <p v-if="campos.pessoa_id" class="text-xs text-destructive">{{ campos.pessoa_id }}</p>
        </div>
        <div v-if="form.pessoa_id === NOVO" class="grid gap-1.5 sm:col-span-2">
          <Label for="m-novo">Nome do investidor</Label>
          <Input id="m-novo" v-model.trim="form.novo_investidor" required maxlength="120" :aria-invalid="!!campos.nome" />
          <p v-if="campos.nome" class="text-xs text-destructive">{{ campos.nome }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="m-valor">Valor (R$)</Label>
          <Input id="m-valor" v-model="form.valor" inputmode="decimal" required placeholder="0,00" :aria-invalid="!!campos.valor" />
          <p v-if="campos.valor" class="text-xs text-destructive">{{ campos.valor }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="m-data">Data</Label>
          <Input id="m-data" v-model="form.data_movimento" type="date" :max="hoje()" required :aria-invalid="!!campos.data_movimento" />
          <p v-if="campos.data_movimento" class="text-xs text-destructive">{{ campos.data_movimento }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label>Conta</Label>
          <Select v-model="form.conta_financeira_id">
            <SelectTrigger class="w-full" :aria-invalid="!!campos.conta_financeira_id"><SelectValue placeholder="Caixa ou banco" /></SelectTrigger>
            <SelectContent><SelectItem v-for="c in contasAtivas" :key="c.id" :value="String(c.id)">{{ c.nome }}</SelectItem></SelectContent>
          </Select>
          <p v-if="campos.conta_financeira_id" class="text-xs text-destructive">{{ campos.conta_financeira_id }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label>Forma</Label>
          <Select v-model="form.forma_pagamento">
            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem v-for="f in opcoes?.forma_pagamento" :key="f.value" :value="f.value">{{ f.label }}</SelectItem></SelectContent>
          </Select>
        </div>
        <div class="grid gap-1.5 sm:col-span-2">
          <Label for="m-doc">Nº do documento</Label>
          <Input id="m-doc" v-model="form.documento" maxlength="60" placeholder="Comprovante, contrato" />
        </div>
        <div class="grid gap-1.5 sm:col-span-2">
          <Label for="m-obs">Observações</Label>
          <Textarea id="m-obs" v-model="form.observacoes" rows="2" />
        </div>
      </form>

      <DialogFooter>
        <Button variant="ghost" @click="formAberto = false">Cancelar</Button>
        <Button type="submit" form="form-movimento" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- Diálogo: cancelar -->
  <Dialog :open="!!cancelando" @update:open="(v) => !v && (cancelando = null)">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Cancelar {{ cancelando?.tipo_label.toLowerCase() }}</DialogTitle>
        <DialogDescription>
          {{ moeda(cancelando?.valor) }} de {{ cancelando?.investidor }} em {{ dataBr(cancelando?.data_movimento) }}.
          O valor sai do saldo da conta, e o registro continua na lista como cancelado.
        </DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="envioCancelamento.mensagens.value" />
      <form id="form-cancelamento" class="grid gap-1.5" @submit.prevent="confirmarCancelamento">
        <Label for="m-motivo">Motivo</Label>
        <Input id="m-motivo" v-model.trim="motivo" required maxlength="255" />
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="cancelando = null">Voltar</Button>
        <Button type="submit" form="form-cancelamento" variant="destructive" :disabled="envioCancelamento.enviando.value">Cancelar movimento</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
