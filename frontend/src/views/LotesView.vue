<script setup>
import { reactive, ref, watch } from 'vue'
import { Info, ListFilter, Pencil, Plus } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import FormLancamento from '../components/financeiro/FormLancamento.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { useCadastrosFinanceiros } from '../composables/useCadastrosFinanceiros'
import { useEnvio } from '../composables/useEnvio'
import { dataBr, hoje, mesCurto, moeda } from '../formatos'

/**
 * Lotes de recria e seus custos (vindos das despesas do financeiro vinculadas ao lote).
 * Aquisição dos animais e custo operacional da recria aparecem sempre separados.
 */
const lotes = ref([])
const incluirEncerrados = ref(false)
const erroCarga = ref([])
const { recarregar } = useCadastrosFinanceiros()

async function carregar() {
  try {
    lotes.value = (await http.get('/lotes', { params: { incluir_encerrados: incluirEncerrados.value ? 1 : 0 } })).data.data
    erroCarga.value = []
  } catch (e) { erroCarga.value = e.mensagens }
}
watch(incluirEncerrados, carregar, { immediate: true })

// Criar / editar
const { enviando, mensagens, campos, enviar } = useEnvio()
const formAberto = ref(false)
const editando = ref(null)
const form = reactive({})
function abrirForm(l = null) {
  editando.value = l
  mensagens.value = []
  campos.value = {}
  Object.assign(form, l
    ? { nome: l.nome, data_formacao: l.data_formacao, categoria: l.categoria ?? '', origem: l.origem ?? '', observacoes: l.observacoes ?? '', data_encerramento: l.data_encerramento ?? '' }
    : { nome: '', data_formacao: hoje(), categoria: '', origem: '', observacoes: '', data_encerramento: '' })
  formAberto.value = true
}
async function salvar() {
  const dados = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
  try {
    const { data } = await enviar(() => editando.value ? http.patch(`/lotes/${editando.value.id}`, dados) : http.post('/lotes', dados))
    toast.success(editando.value ? 'Lote atualizado' : `Lote ${data.data.nome} criado`)
    formAberto.value = false
    if (detalhe.value && editando.value?.id === detalhe.value.id) detalhe.value = data.data
    carregar()
    recarregar()
  } catch { /* exibido no formulário */ }
}

// Detalhe com custos
const detalhe = ref(null)
const erroDetalhe = ref([])
async function abrirDetalhe(l) {
  erroDetalhe.value = []
  try { detalhe.value = (await http.get(`/lotes/${l.id}`)).data.data } catch (e) { erroDetalhe.value = e.mensagens; detalhe.value = { ...l, custos: null } }
}
const lancarAberto = ref(false)
async function lancado() {
  if (detalhe.value) await abrirDetalhe(detalhe.value)
  carregar()
}
</script>

<template>
  <CabecalhoPagina titulo="Lotes" :contador="lotes.length">
    <template #acoes>
      <Button variant="outline" :class="{ 'border-primary text-primary': incluirEncerrados }" @click="incluirEncerrados = !incluirEncerrados">
        <ListFilter /> <span class="hidden sm:inline">{{ incluirEncerrados ? 'Mostrando encerrados' : 'Incluir encerrados' }}</span>
      </Button>
      <Button @click="abrirForm()"><Plus /> <span class="hidden sm:inline">Novo lote</span></Button>
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erroCarga" />
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <button v-for="l in lotes" :key="l.id" class="rounded-xl text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50" @click="abrirDetalhe(l)">
        <Card class="h-full transition hover:shadow-md" :class="{ 'opacity-70': l.data_encerramento }">
          <CardHeader>
            <div class="flex items-start justify-between gap-2">
              <CardTitle class="text-lg">{{ l.nome }}</CardTitle>
              <Badge v-if="l.data_encerramento" variant="outline">Encerrado em {{ dataBr(l.data_encerramento) }}</Badge>
              <Badge v-else class="border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">Ativo</Badge>
            </div>
            <CardDescription>
              {{ [l.categoria, l.origem].filter(Boolean).join(' · ') || 'Sem categoria' }} · formado em {{ dataBr(l.data_formacao) }}
            </CardDescription>
          </CardHeader>
          <CardContent class="grid grid-cols-2 gap-3 text-sm">
            <div>
              <p class="text-xs text-muted-foreground">Despesas</p>
              <p class="font-semibold tabular-nums text-red-700 dark:text-red-400">{{ moeda(l.despesas) }}</p>
            </div>
            <div>
              <p class="text-xs text-muted-foreground">Receitas</p>
              <p class="font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">{{ moeda(l.receitas) }}</p>
            </div>
          </CardContent>
        </Card>
      </button>
    </div>
    <p v-if="!lotes.length" class="text-sm text-muted-foreground">Nenhum lote {{ incluirEncerrados ? '' : 'ativo ' }}cadastrado.</p>
  </main>

  <!-- Detalhe do lote -->
  <Sheet :open="!!detalhe" @update:open="(v) => !v && (detalhe = null)">
    <SheetContent class="w-full overflow-y-auto sm:max-w-2xl">
      <SheetHeader>
        <SheetTitle>{{ detalhe?.nome }}</SheetTitle>
        <SheetDescription v-if="detalhe">
          {{ [detalhe.categoria, detalhe.origem].filter(Boolean).join(' · ') }} · formado em {{ dataBr(detalhe.data_formacao) }}
          <template v-if="detalhe.data_encerramento"> · encerrado em {{ dataBr(detalhe.data_encerramento) }}</template>
        </SheetDescription>
      </SheetHeader>

      <div v-if="detalhe" class="space-y-5 px-4 pb-6">
        <AlertaErros :mensagens="erroDetalhe" />
        <div class="flex flex-wrap gap-2">
          <Button size="sm" @click="lancarAberto = true"><Plus /> Lançar despesa neste lote</Button>
          <Button size="sm" variant="outline" as-child><RouterLink :to="`/financeiro/lancamentos?lote_id=${detalhe.id}`">Ver lançamentos</RouterLink></Button>
          <Button size="sm" variant="ghost" @click="abrirForm(detalhe)"><Pencil /> Editar lote</Button>
        </div>
        <p v-if="detalhe.observacoes" class="whitespace-pre-line text-sm text-muted-foreground">{{ detalhe.observacoes }}</p>

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
            Quantidade de animais, custo por animal e custo por arroba virão com a Fase 3 (animais no lote).
            Os custos aqui são por competência: incluem despesas já pagas e em aberto; cancelados não entram.
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
      </div>
    </SheetContent>
  </Sheet>

  <FormLancamento v-if="detalhe" v-model:open="lancarAberto" :padrao="{ tipo: 'DESPESA', lote_id: detalhe.id }" @salvo="lancado" />

  <!-- Criar / editar lote -->
  <Dialog v-model:open="formAberto">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ editando ? 'Editar lote' : 'Novo lote' }}</DialogTitle>
        <DialogDescription>Encerre o lote (data de encerramento) quando for vendido ou desfeito: o histórico é mantido.</DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />
      <form id="form-lote" class="grid gap-4 sm:grid-cols-2" @submit.prevent="salvar">
        <div class="grid gap-1.5">
          <Label for="lt-nome">Nome</Label>
          <Input id="lt-nome" v-model.trim="form.nome" required maxlength="80" placeholder="Ex.: Lote 01 Nelore" :aria-invalid="!!campos.nome" />
          <p v-if="campos.nome" class="text-xs text-destructive">{{ campos.nome }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="lt-form">Data de formação</Label>
          <Input id="lt-form" v-model="form.data_formacao" type="date" required :aria-invalid="!!campos.data_formacao" />
          <p v-if="campos.data_formacao" class="text-xs text-destructive">{{ campos.data_formacao }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="lt-cat">Categoria</Label>
          <Input id="lt-cat" v-model="form.categoria" maxlength="60" placeholder="Ex.: Garrotes de recria" />
        </div>
        <div class="grid gap-1.5">
          <Label for="lt-orig">Origem</Label>
          <Input id="lt-orig" v-model="form.origem" maxlength="120" placeholder="Ex.: Leilão de Araguaína" />
        </div>
        <div class="grid gap-1.5 sm:col-span-2">
          <Label for="lt-obs">Observações</Label>
          <Textarea id="lt-obs" v-model="form.observacoes" rows="2" />
        </div>
        <div v-if="editando" class="grid gap-1.5">
          <Label for="lt-enc">Data de encerramento</Label>
          <Input id="lt-enc" v-model="form.data_encerramento" type="date" :min="form.data_formacao" :aria-invalid="!!campos.data_encerramento" />
          <p v-if="campos.data_encerramento" class="text-xs text-destructive">{{ campos.data_encerramento }}</p>
        </div>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="formAberto = false">Cancelar</Button>
        <Button type="submit" form="form-lote" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
