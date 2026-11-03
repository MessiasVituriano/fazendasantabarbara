<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Pencil, Plus, Search } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import http from '../../api/http'
import AlertaErros from '../../components/AlertaErros.vue'
import CabecalhoPagina from '../../components/layout/CabecalhoPagina.vue'
import { useCadastrosFinanceiros } from '../../composables/useCadastrosFinanceiros'
import { useEnvio } from '../../composables/useEnvio'
import { useOpcoes } from '../../composables/useOpcoes'
import { dataBr, formatarTelefone, hoje, mascaraTelefone, moeda, valorParaApi } from '../../formatos'

/** Cadastros do financeiro: contas (caixa/banco), categorias (plano de contas) e fornecedores/clientes. */
const route = useRoute()
const router = useRouter()
const ABAS = ['contas', 'categorias', 'pessoas']
const aba = ref(ABAS.includes(route.query.aba) ? route.query.aba : 'contas')
watch(aba, (v) => {
  if (!v) return (aba.value = route.query.aba && ABAS.includes(route.query.aba) ? route.query.aba : 'contas')
  router.replace({ query: { ...route.query, aba: v } })
})

const { opcoes } = useOpcoes()
const { recarregar } = useCadastrosFinanceiros()
const { enviando, mensagens, campos, enviar } = useEnvio()
const erroCarga = ref([])
const limparErros = () => { mensagens.value = []; campos.value = {} }

// ---------------- Contas ----------------
const contas = ref([])
async function carregarContas() {
  try { contas.value = (await http.get('/financeiro/contas')).data.data } catch (e) { erroCarga.value = e.mensagens }
}
const saldoTotal = computed(() => contas.value.reduce((s, c) => s + Number(c.saldo_atual || 0), 0))

const contaAberta = ref(false)
const contaEditando = ref(null)
const formConta = reactive({})
function abrirConta(c = null) {
  contaEditando.value = c
  limparErros()
  Object.assign(formConta, c
    ? { nome: c.nome, tipo: c.tipo, saldo_inicial: String(c.saldo_inicial).replace('.', ','), data_saldo_inicial: c.data_saldo_inicial, ativa: c.ativa }
    : { nome: '', tipo: 'CONTA_CORRENTE', saldo_inicial: '0,00', data_saldo_inicial: hoje(), ativa: true })
  contaAberta.value = true
}
async function salvarConta() {
  const dados = { ...formConta, saldo_inicial: valorParaApi(formConta.saldo_inicial) ?? '0' }
  if (!contaEditando.value) delete dados.ativa
  try {
    await enviar(() => contaEditando.value
      ? http.patch(`/financeiro/contas/${contaEditando.value.id}`, dados)
      : http.post('/financeiro/contas', dados))
    toast.success(contaEditando.value ? 'Conta atualizada' : 'Conta criada')
    contaAberta.value = false
    carregarContas()
    recarregar()
  } catch { /* exibido no formulário */ }
}

// ---------------- Categorias ----------------
const categorias = ref([])
async function carregarCategorias() {
  try { categorias.value = (await http.get('/financeiro/categorias')).data.data } catch (e) { erroCarga.value = e.mensagens }
}
const receitas = computed(() => categorias.value.filter((c) => c.tipo === 'RECEITA'))
const despesas = computed(() => categorias.value.filter((c) => c.tipo === 'DESPESA'))

const catAberta = ref(false)
const catEditando = ref(null)
const formCat = reactive({})
function abrirCategoria(c = null, tipo = 'DESPESA') {
  catEditando.value = c
  limparErros()
  Object.assign(formCat, c
    ? { tipo: c.tipo, nome: c.nome, grupo_custo: c.grupo_custo, ativa: c.ativa }
    : { tipo, nome: '', grupo_custo: tipo === 'DESPESA' ? null : null, ativa: true })
  catAberta.value = true
}
async function salvarCategoria() {
  const dados = { nome: formCat.nome }
  if (formCat.tipo === 'DESPESA') dados.grupo_custo = formCat.grupo_custo
  if (catEditando.value) dados.ativa = formCat.ativa
  else dados.tipo = formCat.tipo
  try {
    await enviar(() => catEditando.value
      ? http.patch(`/financeiro/categorias/${catEditando.value.id}`, dados)
      : http.post('/financeiro/categorias', dados))
    toast.success(catEditando.value ? 'Categoria atualizada' : 'Categoria criada')
    catAberta.value = false
    carregarCategorias()
    recarregar()
  } catch { /* exibido no formulário */ }
}

// ---------------- Pessoas ----------------
const pessoas = ref([])
const busca = ref('')
let espera
async function carregarPessoas() {
  try { pessoas.value = (await http.get('/pessoas', { params: { busca: busca.value || undefined } })).data.data } catch (e) { erroCarga.value = e.mensagens }
}
watch(busca, () => { clearTimeout(espera); espera = setTimeout(carregarPessoas, 300) })

const pessoaAberta = ref(false)
const pessoaEditando = ref(null)
const formPessoa = reactive({})
function abrirPessoa(p = null) {
  pessoaEditando.value = p
  limparErros()
  Object.assign(formPessoa, p
    ? { nome: p.nome, tipo: p.tipo, documento: p.documento ?? '', telefone: formatarTelefone(p.telefone), observacoes: p.observacoes ?? '', ativo: p.ativo }
    : { nome: '', tipo: 'FORNECEDOR', documento: '', telefone: '', observacoes: '', ativo: true })
  pessoaAberta.value = true
}
async function salvarPessoa() {
  const dados = { ...formPessoa, documento: formPessoa.documento || null, telefone: formPessoa.telefone || null, observacoes: formPessoa.observacoes || null }
  try {
    await enviar(() => pessoaEditando.value
      ? http.patch(`/pessoas/${pessoaEditando.value.id}`, dados)
      : http.post('/pessoas', dados))
    toast.success(pessoaEditando.value ? 'Cadastro atualizado' : 'Cadastro criado')
    pessoaAberta.value = false
    carregarPessoas()
    recarregar()
  } catch { /* exibido no formulário */ }
}
/** CPF (11) / CNPJ (14) formatados; outros documentos como vieram. */
function documentoFmt(d) {
  if (!d) return '—'
  if (d.length === 11) return d.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')
  if (d.length === 14) return d.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5')
  return d
}

carregarContas()
carregarCategorias()
carregarPessoas()

const novo = () => (aba.value === 'contas' ? abrirConta() : aba.value === 'categorias' ? abrirCategoria() : abrirPessoa())
const rotuloNovo = computed(() => ({ contas: 'Nova conta', categorias: 'Nova categoria', pessoas: 'Novo cadastro' })[aba.value])
const SEL = 'px-3 data-[state=on]:bg-primary data-[state=on]:text-primary-foreground data-[state=on]:hover:bg-primary/90'
</script>

<template>
  <CabecalhoPagina titulo="Contas e categorias">
    <template #acoes><Button @click="novo"><Plus /> <span class="hidden sm:inline">{{ rotuloNovo }}</span></Button></template>
    <template #filtros>
      <ToggleGroup v-model="aba" type="single" variant="outline" size="sm" aria-label="Seção">
        <ToggleGroupItem value="contas" :class="SEL">Contas</ToggleGroupItem>
        <ToggleGroupItem value="categorias" :class="SEL">Categorias</ToggleGroupItem>
        <ToggleGroupItem value="pessoas" :class="SEL">Fornecedores e clientes</ToggleGroupItem>
      </ToggleGroup>
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erroCarga" />

    <!-- CONTAS -->
    <template v-if="aba === 'contas'">
      <p class="text-sm text-muted-foreground">
        Onde o dinheiro da fazenda fica: caixa (dinheiro vivo) e contas bancárias. O saldo atual é calculado
        a partir do saldo inicial e de tudo que foi efetivamente pago ou recebido.
      </p>
      <div class="rounded-xl border bg-card p-4">
        <p class="text-xs text-muted-foreground">Saldo total disponível</p>
        <p class="text-2xl font-semibold tabular-nums" :class="saldoTotal < 0 ? 'text-red-700 dark:text-red-400' : ''">{{ moeda(saldoTotal) }}</p>
      </div>
      <div class="overflow-x-auto rounded-xl border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Conta</TableHead><TableHead>Tipo</TableHead><TableHead class="text-right">Saldo inicial</TableHead>
              <TableHead class="text-right">Saldo atual</TableHead><TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="c in contas" :key="c.id" :class="{ 'opacity-60': !c.ativa }">
              <TableCell>
                <p class="font-medium">{{ c.nome }}</p>
                <Badge v-if="!c.ativa" variant="outline" class="mt-0.5">Desativada</Badge>
              </TableCell>
              <TableCell class="text-sm">{{ c.tipo_label }}</TableCell>
              <TableCell class="text-right text-sm tabular-nums">
                {{ moeda(c.saldo_inicial) }}<br /><span class="text-xs text-muted-foreground">em {{ dataBr(c.data_saldo_inicial) }}</span>
              </TableCell>
              <TableCell class="text-right font-semibold tabular-nums" :class="c.saldo_atual < 0 ? 'text-red-700 dark:text-red-400' : ''">{{ moeda(c.saldo_atual) }}</TableCell>
              <TableCell class="text-right"><Button variant="ghost" size="icon" :aria-label="`Editar ${c.nome}`" @click="abrirConta(c)"><Pencil /></Button></TableCell>
            </TableRow>
            <TableRow v-if="!contas.length"><TableCell colspan="5" class="text-muted-foreground">Nenhuma conta cadastrada. Cadastre o caixa e as contas bancárias para registrar pagamentos.</TableCell></TableRow>
          </TableBody>
        </Table>
      </div>
    </template>

    <!-- CATEGORIAS -->
    <template v-else-if="aba === 'categorias'">
      <p class="text-sm text-muted-foreground">
        Cada despesa pertence a um grupo de custo da recria. "Aquisição dos animais" fica sempre separada do custo operacional.
      </p>
      <div class="grid gap-4 lg:grid-cols-2">
        <section v-for="grupo in [{ titulo: 'Receitas', tipo: 'RECEITA', itens: receitas }, { titulo: 'Despesas', tipo: 'DESPESA', itens: despesas }]" :key="grupo.tipo" class="overflow-hidden rounded-xl border bg-card">
          <div class="flex items-center justify-between border-b px-4 py-2.5">
            <h2 class="text-sm font-semibold">{{ grupo.titulo }} <span class="font-normal text-muted-foreground">({{ grupo.itens.length }})</span></h2>
            <Button size="sm" variant="ghost" @click="abrirCategoria(null, grupo.tipo)"><Plus /> Nova</Button>
          </div>
          <ul class="divide-y">
            <li v-for="c in grupo.itens" :key="c.id" class="flex items-center gap-3 px-4 py-2" :class="{ 'opacity-60': !c.ativa }">
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">{{ c.nome }}</p>
                <p class="text-xs text-muted-foreground">
                  <template v-if="c.grupo_custo_label">{{ c.grupo_custo_label }} · </template>{{ c.lancamentos_count }} lançamento(s)
                  <template v-if="c.chave"> · padrão</template>
                </p>
              </div>
              <Badge v-if="!c.ativa" variant="outline">Desativada</Badge>
              <Button variant="ghost" size="icon" class="size-8" :aria-label="`Editar ${c.nome}`" @click="abrirCategoria(c)"><Pencil /></Button>
            </li>
          </ul>
        </section>
      </div>
    </template>

    <!-- PESSOAS -->
    <template v-else>
      <div class="relative max-w-sm">
        <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input v-model="busca" placeholder="Buscar por nome ou CPF/CNPJ" class="pl-8" />
      </div>
      <div class="overflow-x-auto rounded-xl border bg-card">
        <Table>
          <TableHeader>
            <TableRow><TableHead>Nome</TableHead><TableHead>Tipo</TableHead><TableHead>CPF/CNPJ</TableHead><TableHead>Telefone</TableHead><TableHead /></TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="p in pessoas" :key="p.id" :class="{ 'opacity-60': !p.ativo }">
              <TableCell>
                <p class="font-medium">{{ p.nome }}</p>
                <p v-if="p.observacoes" class="max-w-xs truncate text-xs text-muted-foreground">{{ p.observacoes }}</p>
              </TableCell>
              <TableCell class="text-sm">{{ p.tipo_label }} <Badge v-if="!p.ativo" variant="outline" class="ml-1">Inativo</Badge></TableCell>
              <TableCell class="font-mono text-sm">{{ documentoFmt(p.documento) }}</TableCell>
              <TableCell class="text-sm">{{ formatarTelefone(p.telefone) || '—' }}</TableCell>
              <TableCell class="text-right"><Button variant="ghost" size="icon" :aria-label="`Editar ${p.nome}`" @click="abrirPessoa(p)"><Pencil /></Button></TableCell>
            </TableRow>
            <TableRow v-if="!pessoas.length"><TableCell colspan="5" class="text-muted-foreground">Nenhum fornecedor ou cliente encontrado.</TableCell></TableRow>
          </TableBody>
        </Table>
      </div>
    </template>
  </main>

  <!-- Diálogo: conta -->
  <Dialog v-model:open="contaAberta">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ contaEditando ? 'Editar conta' : 'Nova conta' }}</DialogTitle>
        <DialogDescription>
          O saldo inicial é o saldo da conta na data informada. Pagamentos anteriores a essa data não são aceitos nesta conta.
        </DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />
      <form id="form-conta" class="grid gap-4 sm:grid-cols-2" @submit.prevent="salvarConta">
        <div class="grid gap-1.5 sm:col-span-2">
          <Label for="c-nome">Nome</Label>
          <Input id="c-nome" v-model.trim="formConta.nome" required maxlength="80" placeholder="Ex.: Banco do Brasil, Caixa da fazenda" :aria-invalid="!!campos.nome" />
          <p v-if="campos.nome" class="text-xs text-destructive">{{ campos.nome }}</p>
        </div>
        <div class="grid gap-1.5 sm:col-span-2">
          <Label>Tipo</Label>
          <Select v-model="formConta.tipo">
            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem v-for="t in opcoes?.tipo_conta_financeira" :key="t.value" :value="t.value">{{ t.label }}</SelectItem></SelectContent>
          </Select>
        </div>
        <div class="grid gap-1.5">
          <Label for="c-saldo">Saldo inicial (R$)</Label>
          <Input id="c-saldo" v-model="formConta.saldo_inicial" inputmode="decimal" required :aria-invalid="!!campos.saldo_inicial" />
          <p v-if="campos.saldo_inicial" class="text-xs text-destructive">{{ campos.saldo_inicial }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="c-data">Data do saldo</Label>
          <Input id="c-data" v-model="formConta.data_saldo_inicial" type="date" required :aria-invalid="!!campos.data_saldo_inicial" />
          <p v-if="campos.data_saldo_inicial" class="text-xs text-destructive">{{ campos.data_saldo_inicial }}</p>
        </div>
        <label v-if="contaEditando" class="flex items-center gap-2 text-sm sm:col-span-2">
          <input v-model="formConta.ativa" type="checkbox" class="size-4 accent-primary" /> Conta ativa (desative em vez de excluir)
        </label>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="contaAberta = false">Cancelar</Button>
        <Button type="submit" form="form-conta" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- Diálogo: categoria -->
  <Dialog v-model:open="catAberta">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ catEditando ? 'Editar categoria' : 'Nova categoria' }}</DialogTitle>
        <DialogDescription v-if="catEditando">
          Mudar o grupo de custo vale só para lançamentos novos; os antigos mantêm o grupo da época.
        </DialogDescription>
        <DialogDescription v-else>Categorias organizam receitas e despesas nos relatórios.</DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />
      <form id="form-categoria" class="grid gap-4" @submit.prevent="salvarCategoria">
        <div v-if="!catEditando" class="grid gap-1.5">
          <Label>Tipo</Label>
          <ToggleGroup v-model="formCat.tipo" type="single" variant="outline" class="w-full">
            <ToggleGroupItem value="DESPESA" class="flex-1" :class="SEL">Despesa</ToggleGroupItem>
            <ToggleGroupItem value="RECEITA" class="flex-1" :class="SEL">Receita</ToggleGroupItem>
          </ToggleGroup>
        </div>
        <div class="grid gap-1.5">
          <Label for="cat-nome">Nome</Label>
          <Input id="cat-nome" v-model.trim="formCat.nome" required maxlength="80" :aria-invalid="!!campos.nome" />
          <p v-if="campos.nome" class="text-xs text-destructive">{{ campos.nome }}</p>
        </div>
        <div v-if="formCat.tipo === 'DESPESA'" class="grid gap-1.5">
          <Label>Grupo de custo</Label>
          <Select v-model="formCat.grupo_custo">
            <SelectTrigger class="w-full" :aria-invalid="!!campos.grupo_custo"><SelectValue placeholder="Escolha o grupo" /></SelectTrigger>
            <SelectContent><SelectItem v-for="g in opcoes?.grupo_custo" :key="g.value" :value="g.value">{{ g.label }}</SelectItem></SelectContent>
          </Select>
          <p v-if="campos.grupo_custo" class="text-xs text-destructive">{{ campos.grupo_custo }}</p>
        </div>
        <label v-if="catEditando" class="flex items-center gap-2 text-sm">
          <input v-model="formCat.ativa" type="checkbox" class="size-4 accent-primary" /> Categoria ativa
        </label>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="catAberta = false">Cancelar</Button>
        <Button type="submit" form="form-categoria" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- Diálogo: pessoa -->
  <Dialog v-model:open="pessoaAberta">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ pessoaEditando ? 'Editar cadastro' : 'Novo fornecedor ou cliente' }}</DialogTitle>
        <DialogDescription>Casas agropecuárias, vendedores e compradores de gado, transportadores...</DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />
      <form id="form-pessoa" class="grid gap-4 sm:grid-cols-2" @submit.prevent="salvarPessoa">
        <div class="grid gap-1.5 sm:col-span-2">
          <Label for="p-nome">Nome</Label>
          <Input id="p-nome" v-model.trim="formPessoa.nome" required maxlength="120" :aria-invalid="!!campos.nome" />
          <p v-if="campos.nome" class="text-xs text-destructive">{{ campos.nome }}</p>
        </div>
        <div class="grid gap-1.5 sm:col-span-2">
          <Label>Tipo</Label>
          <Select v-model="formPessoa.tipo">
            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem v-for="t in opcoes?.tipo_pessoa" :key="t.value" :value="t.value">{{ t.label }}</SelectItem></SelectContent>
          </Select>
        </div>
        <div class="grid gap-1.5">
          <Label for="p-doc">CPF/CNPJ</Label>
          <Input id="p-doc" v-model="formPessoa.documento" inputmode="numeric" maxlength="20" :aria-invalid="!!campos.documento" />
          <p v-if="campos.documento" class="text-xs text-destructive">{{ campos.documento }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="p-tel">Telefone</Label>
          <Input id="p-tel" :model-value="formPessoa.telefone" inputmode="tel" placeholder="(00) 00000-0000" :aria-invalid="!!campos.telefone"
            @update:model-value="(v) => (formPessoa.telefone = mascaraTelefone(v))" />
          <p v-if="campos.telefone" class="text-xs text-destructive">{{ campos.telefone }}</p>
        </div>
        <div class="grid gap-1.5 sm:col-span-2">
          <Label for="p-obs">Observações</Label>
          <Textarea id="p-obs" v-model="formPessoa.observacoes" rows="2" />
        </div>
        <label v-if="pessoaEditando" class="flex items-center gap-2 text-sm sm:col-span-2">
          <input v-model="formPessoa.ativo" type="checkbox" class="size-4 accent-primary" /> Cadastro ativo
        </label>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="pessoaAberta = false">Cancelar</Button>
        <Button type="submit" form="form-pessoa" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
