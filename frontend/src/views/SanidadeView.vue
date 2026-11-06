<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Pencil, Plus, Search, Trash2 } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import FormAplicacaoSanitaria from '../components/FormAplicacaoSanitaria.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { avisarGuardadoOffline } from '../offline/fila'
import { useCadastrosSanitarios } from '../composables/useCadastrosSanitarios'
import { useEnvio } from '../composables/useEnvio'
import { useOpcoes } from '../composables/useOpcoes'
import { useSessao } from '../composables/useSessao'
import { dataBr, hoje } from '../formatos'

/** Aplicações de vacinas e medicamentos; o gestor também mantém as categorias e os produtos. */
const route = useRoute()
const router = useRouter()
const { ehGestor } = useSessao()
const { opcoes, rotulo } = useOpcoes()
const { categorias, produtos, categoriasAtivas, recarregar } = useCadastrosSanitarios()
const { enviando, mensagens, campos, enviar } = useEnvio()
const limparErros = () => { mensagens.value = []; campos.value = {} }
const erroCarga = ref([])

const ABAS = ['aplicacoes', 'categorias', 'produtos']
const abaInicial = () => (ehGestor.value && ABAS.includes(route.query.aba) ? route.query.aba : 'aplicacoes')
const aba = ref(abaInicial())
watch(aba, (v) => {
  if (!v) return (aba.value = abaInicial())
  router.replace({ query: { ...route.query, aba: v } })
})

// ---------------- Aplicações ----------------
const aplicacoes = ref([])
const pagina = ref(1)
const ultimaPagina = ref(1)
const total = ref(0)
const carregando = ref(false)
const filtros = reactive({ tipo: 'TODOS', busca: '', vigentes: false })

async function carregar() {
  carregando.value = true
  try {
    const { data } = await http.get('/vacinas', { params: {
      tipo: filtros.tipo === 'TODOS' ? undefined : filtros.tipo,
      busca: filtros.busca || undefined,
      vigentes: filtros.vigentes ? 1 : undefined,
      page: pagina.value,
    } })
    aplicacoes.value = data.data
    ultimaPagina.value = data.meta.last_page
    total.value = data.meta.total
    erroCarga.value = []
  } catch (e) {
    erroCarga.value = e.mensagens
  } finally {
    carregando.value = false
  }
}
carregar()

function irParaPagina(p) {
  pagina.value = p
  carregar()
}
function aplicarFiltros() {
  pagina.value = 1
  carregar()
}
watch(() => [filtros.tipo, filtros.vigentes], aplicarFiltros)
let espera
watch(() => filtros.busca, () => { clearTimeout(espera); espera = setTimeout(aplicarFiltros, 300) })

const emCarencia = (a) => a.data_fim_carencia >= hoje()

const novaAplicacao = ref(false)
function aplicacaoSalva(aplicacao) {
  if (aplicacao) toast.success('Aplicação registrada')
  else avisarGuardadoOffline('Aplicação')
  novaAplicacao.value = false
  aplicarFiltros()
}

const excluindo = ref(null)
async function excluir() {
  try {
    await enviar(() => http.delete(`/vacinas/${excluindo.value.id}`))
    toast.success('Aplicação excluída')
    excluindo.value = null
    carregar()
  } catch { /* exibido no diálogo */ }
}

// ---------------- Categorias ----------------
const grupos = computed(() => (opcoes.value?.tipo_produto_sanitario ?? []).map((t) => ({
  tipo: t.value, titulo: t.value === 'VACINA' ? 'Vacinas' : 'Medicamentos', itens: categorias.value.filter((c) => c.tipo === t.value),
})))
const catAberta = ref(false)
const catEditando = ref(null)
const formCat = reactive({})
function abrirCategoria(c = null, tipo = 'VACINA') {
  catEditando.value = c
  limparErros()
  Object.assign(formCat, c
    ? { tipo: c.tipo, nome: c.nome, dias: c.dias_carencia_padrao ?? '', ativa: c.ativa }
    : { tipo, nome: '', dias: '', ativa: true })
  catAberta.value = true
}
async function salvarCategoria() {
  const dados = { nome: formCat.nome, dias_carencia_padrao: formCat.dias === '' ? null : formCat.dias }
  if (catEditando.value) dados.ativa = formCat.ativa
  else dados.tipo = formCat.tipo
  try {
    await enviar(() => catEditando.value
      ? http.patch(`/sanidade/categorias/${catEditando.value.id}`, dados)
      : http.post('/sanidade/categorias', dados))
    toast.success(catEditando.value ? 'Categoria atualizada' : 'Categoria criada')
    catAberta.value = false
    recarregar()
  } catch { /* exibido no formulário */ }
}

// ---------------- Produtos ----------------
const diasDaCategoria = (id) => categorias.value.find((c) => c.id === Number(id))?.dias_carencia_padrao ?? null
const prodAberto = ref(false)
const prodEditando = ref(null)
const formProd = reactive({})
const categoriasDoProduto = computed(() => categoriasAtivas.value.some((c) => c.id === prodEditando.value?.categoria_sanitaria_id)
  ? categoriasAtivas.value
  : [...categoriasAtivas.value, ...categorias.value.filter((c) => c.id === prodEditando.value?.categoria_sanitaria_id)])
function abrirProduto(p = null) {
  prodEditando.value = p
  limparErros()
  Object.assign(formProd, p
    ? { categoria: String(p.categoria_sanitaria_id), nome: p.nome, dias: p.dias_carencia ?? '', ativo: p.ativo }
    : { categoria: '', nome: '', dias: '', ativo: true })
  prodAberto.value = true
}
async function salvarProduto() {
  const dados = { categoria_sanitaria_id: Number(formProd.categoria) || null, nome: formProd.nome, dias_carencia: formProd.dias === '' ? null : formProd.dias }
  if (prodEditando.value) dados.ativo = formProd.ativo
  try {
    await enviar(() => prodEditando.value
      ? http.patch(`/sanidade/produtos/${prodEditando.value.id}`, dados)
      : http.post('/sanidade/produtos', dados))
    toast.success(prodEditando.value ? 'Produto atualizado' : 'Produto cadastrado')
    prodAberto.value = false
    recarregar()
  } catch { /* exibido no formulário */ }
}

const novo = () => (aba.value === 'aplicacoes' ? (novaAplicacao.value = true) : aba.value === 'categorias' ? abrirCategoria() : abrirProduto())
const rotuloNovo = computed(() => ({ aplicacoes: 'Nova aplicação', categorias: 'Nova categoria', produtos: 'Novo produto' })[aba.value])
const SEL = 'px-3 data-[state=on]:bg-primary data-[state=on]:text-primary-foreground data-[state=on]:hover:bg-primary/90'
</script>

<template>
  <CabecalhoPagina titulo="Sanidade">
    <template #acoes><Button @click="novo"><Plus /> <span class="hidden sm:inline">{{ rotuloNovo }}</span></Button></template>
    <template #filtros>
      <ToggleGroup v-if="ehGestor" v-model="aba" type="single" variant="outline" size="sm" aria-label="Seção">
        <ToggleGroupItem value="aplicacoes" :class="SEL">Aplicações</ToggleGroupItem>
        <ToggleGroupItem value="categorias" :class="SEL">Categorias</ToggleGroupItem>
        <ToggleGroupItem value="produtos" :class="SEL">Vacinas e medicamentos</ToggleGroupItem>
      </ToggleGroup>
      <template v-if="aba === 'aplicacoes'">
        <ToggleGroup :model-value="filtros.tipo" type="single" variant="outline" size="sm" aria-label="Tipo" @update:model-value="(v) => v && (filtros.tipo = v)">
          <ToggleGroupItem value="TODOS" :class="SEL">Todos</ToggleGroupItem>
          <ToggleGroupItem v-for="t in opcoes?.tipo_produto_sanitario" :key="t.value" :value="t.value" :class="SEL">{{ t.label }}</ToggleGroupItem>
        </ToggleGroup>
        <div class="relative w-full sm:w-64">
          <Search class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input v-model="filtros.busca" placeholder="Buscar por produto ou animal" class="pl-8" />
        </div>
        <label class="flex items-center gap-2 text-sm pointer-coarse:min-h-11">
          <input v-model="filtros.vigentes" type="checkbox" class="size-4 accent-primary" /> Só em carência
        </label>
      </template>
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erroCarga" />

    <!-- APLICAÇÕES -->
    <template v-if="aba === 'aplicacoes'">
      <div class="overflow-x-auto rounded-xl border bg-card" :class="{ 'opacity-50': carregando }">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Data</TableHead><TableHead>Animal</TableHead><TableHead>Produto</TableHead>
              <TableHead class="text-right">Carência</TableHead><TableHead>Situação</TableHead><TableHead v-if="ehGestor" />
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="a in aplicacoes" :key="a.id">
              <TableCell class="whitespace-nowrap text-sm tabular-nums">{{ dataBr(a.data_aplicacao) }}</TableCell>
              <TableCell class="text-sm">
                <RouterLink :to="`/animais/${a.animal_id}`" class="font-medium hover:underline pointer-coarse:inline-flex pointer-coarse:min-h-11 pointer-coarse:items-center">{{ a.animal?.nome || a.animal?.brinco }}</RouterLink>
                <p v-if="a.animal?.nome" class="font-mono text-xs text-muted-foreground">{{ a.animal.brinco }}</p>
              </TableCell>
              <TableCell>
                <p class="font-medium">{{ a.nome_vacina }}</p>
                <p class="text-xs text-muted-foreground">{{ [a.tipo && rotulo('tipo_produto_sanitario', a.tipo), a.categoria?.nome].filter(Boolean).join(' · ') || 'Sem classificação' }}</p>
              </TableCell>
              <TableCell class="text-right text-sm tabular-nums">{{ a.dias_carencia_leite_carne }} dia(s)</TableCell>
              <TableCell class="whitespace-nowrap">
                <Badge v-if="emCarencia(a)" variant="destructive">Em carência até {{ dataBr(a.data_fim_carencia) }}</Badge>
                <Badge v-else variant="secondary">Liberado</Badge>
              </TableCell>
              <TableCell v-if="ehGestor" class="text-right">
                <Button variant="ghost" size="icon" :aria-label="`Excluir aplicação de ${a.nome_vacina}`" @click="limparErros(); excluindo = a"><Trash2 /></Button>
              </TableCell>
            </TableRow>
            <TableRow v-if="!aplicacoes.length && !carregando"><TableCell :colspan="ehGestor ? 6 : 5" class="text-muted-foreground">Nenhuma aplicação encontrada.</TableCell></TableRow>
          </TableBody>
        </Table>
      </div>
      <div v-if="ultimaPagina > 1" class="flex items-center justify-between gap-2">
        <p class="text-sm text-muted-foreground tabular-nums">{{ total }} aplicações · página {{ pagina }} de {{ ultimaPagina }}</p>
        <div class="flex gap-2">
          <Button variant="outline" size="sm" :disabled="pagina <= 1" @click="irParaPagina(pagina - 1)">Anterior</Button>
          <Button variant="outline" size="sm" :disabled="pagina >= ultimaPagina" @click="irParaPagina(pagina + 1)">Próxima</Button>
        </div>
      </div>
    </template>

    <!-- CATEGORIAS -->
    <template v-else-if="aba === 'categorias'">
      <p class="text-sm text-muted-foreground">
        A carência da categoria é só uma sugestão para o formulário. Revise os valores de acordo com as bulas que a fazenda usa.
        Alterar uma categoria não muda as aplicações já registradas.
      </p>
      <div class="grid gap-4 lg:grid-cols-2">
        <section v-for="grupo in grupos" :key="grupo.tipo" class="overflow-hidden rounded-xl border bg-card">
          <div class="flex items-center justify-between border-b px-4 py-2.5">
            <h2 class="text-sm font-semibold">{{ grupo.titulo }} <span class="font-normal text-muted-foreground">({{ grupo.itens.length }})</span></h2>
            <Button size="sm" variant="ghost" @click="abrirCategoria(null, grupo.tipo)"><Plus /> Nova</Button>
          </div>
          <ul class="divide-y">
            <li v-for="c in grupo.itens" :key="c.id" class="flex items-center gap-3 px-4 py-2" :class="{ 'opacity-60': !c.ativa }">
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">{{ c.nome }}</p>
                <p class="text-xs text-muted-foreground">
                  {{ c.dias_carencia_padrao == null ? 'Sem sugestão de carência' : `Sugestão: ${c.dias_carencia_padrao} dia(s)` }}
                  · {{ c.produtos_count }} produto(s)<template v-if="c.chave"> · padrão</template>
                </p>
              </div>
              <Badge v-if="!c.ativa" variant="outline">Desativada</Badge>
              <Button variant="ghost" size="icon" class="size-8" :aria-label="`Editar ${c.nome}`" @click="abrirCategoria(c)"><Pencil /></Button>
            </li>
          </ul>
        </section>
      </div>
    </template>

    <!-- PRODUTOS -->
    <template v-else>
      <p class="text-sm text-muted-foreground">
        Cadastre as vacinas e os medicamentos usados na fazenda. A carência do produto prevalece sobre a da categoria.
      </p>
      <div class="overflow-x-auto rounded-xl border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Nome</TableHead><TableHead>Tipo</TableHead><TableHead>Categoria</TableHead>
              <TableHead class="text-right">Carência sugerida</TableHead><TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="p in produtos" :key="p.id" :class="{ 'opacity-60': !p.ativo }">
              <TableCell>
                <p class="font-medium">{{ p.nome }}</p>
                <Badge v-if="!p.ativo" variant="outline" class="mt-0.5">Desativado</Badge>
              </TableCell>
              <TableCell class="text-sm">{{ rotulo('tipo_produto_sanitario', p.categoria?.tipo) }}</TableCell>
              <TableCell class="text-sm">{{ p.categoria?.nome }}</TableCell>
              <TableCell class="text-right text-sm tabular-nums">
                <template v-if="p.dias_carencia != null">{{ p.dias_carencia }} dia(s)</template>
                <template v-else-if="diasDaCategoria(p.categoria_sanitaria_id) != null">{{ diasDaCategoria(p.categoria_sanitaria_id) }} dia(s) <span class="text-xs text-muted-foreground">(categoria)</span></template>
                <template v-else>—</template>
              </TableCell>
              <TableCell class="text-right"><Button variant="ghost" size="icon" :aria-label="`Editar ${p.nome}`" @click="abrirProduto(p)"><Pencil /></Button></TableCell>
            </TableRow>
            <TableRow v-if="!produtos.length"><TableCell colspan="5" class="text-muted-foreground">Nenhum produto cadastrado. Enquanto isso, o nome pode ser digitado livremente em cada aplicação.</TableCell></TableRow>
          </TableBody>
        </Table>
      </div>
    </template>
  </main>

  <!-- Diálogo: aplicação -->
  <Dialog v-model:open="novaAplicacao">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>Nova aplicação</DialogTitle>
        <DialogDescription>Registre a vacina ou o medicamento aplicado. A carência bloqueia o animal na ordenha e no abate.</DialogDescription>
      </DialogHeader>
      <FormAplicacaoSanitaria v-if="novaAplicacao" @salvo="aplicacaoSalva" @cancelar="novaAplicacao = false" />
    </DialogContent>
  </Dialog>

  <!-- Diálogo: excluir aplicação -->
  <Dialog :open="!!excluindo" @update:open="(v) => !v && (excluindo = null)">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Excluir aplicação?</DialogTitle>
        <DialogDescription v-if="excluindo">
          {{ excluindo.nome_vacina }} em {{ dataBr(excluindo.data_aplicacao) }}. Se o animal estiver em carência por causa dela, o alerta deixa de aparecer.
        </DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />
      <DialogFooter>
        <Button variant="ghost" @click="excluindo = null">Cancelar</Button>
        <Button variant="destructive" :disabled="enviando" @click="excluir">Excluir</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- Diálogo: categoria -->
  <Dialog v-model:open="catAberta">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ catEditando ? 'Editar categoria' : 'Nova categoria' }}</DialogTitle>
        <DialogDescription>A carência fica como sugestão no registro das aplicações.</DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="Object.keys(campos).length ? [] : mensagens" />
      <form id="form-categoria-sanitaria" class="grid gap-4" @submit.prevent="salvarCategoria">
        <div v-if="!catEditando" class="grid gap-1.5">
          <Label>Tipo</Label>
          <Select v-model="formCat.tipo">
            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem v-for="t in opcoes?.tipo_produto_sanitario" :key="t.value" :value="t.value">{{ t.label }}</SelectItem></SelectContent>
          </Select>
        </div>
        <div class="grid gap-1.5">
          <Label for="cat-nome">Nome</Label>
          <Input id="cat-nome" v-model.trim="formCat.nome" required maxlength="80" :aria-invalid="!!campos.nome" />
          <p v-if="campos.nome" class="text-xs text-destructive">{{ campos.nome }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="cat-dias">Carência sugerida (dias)</Label>
          <Input id="cat-dias" v-model.number="formCat.dias" type="number" min="0" max="3650" placeholder="Sem sugestão" :aria-invalid="!!campos.dias_carencia_padrao" />
          <p v-if="campos.dias_carencia_padrao" class="text-xs text-destructive">{{ campos.dias_carencia_padrao }}</p>
        </div>
        <label v-if="catEditando" class="flex items-center gap-2 text-sm">
          <input v-model="formCat.ativa" type="checkbox" class="size-4 accent-primary" /> Categoria ativa
        </label>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="catAberta = false">Cancelar</Button>
        <Button type="submit" form="form-categoria-sanitaria" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- Diálogo: produto -->
  <Dialog v-model:open="prodAberto">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ prodEditando ? 'Editar produto' : 'Nova vacina ou medicamento' }}</DialogTitle>
        <DialogDescription>Fica disponível na lista ao registrar uma aplicação.</DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="Object.keys(campos).length ? [] : mensagens" />
      <form id="form-produto-sanitario" class="grid gap-4" @submit.prevent="salvarProduto">
        <div class="grid gap-1.5">
          <Label>Categoria</Label>
          <Select v-model="formProd.categoria">
            <SelectTrigger class="w-full" :aria-invalid="!!campos.categoria_sanitaria_id"><SelectValue placeholder="Selecione…" /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="c in categoriasDoProduto" :key="c.id" :value="String(c.id)">{{ rotulo('tipo_produto_sanitario', c.tipo) }} · {{ c.nome }}</SelectItem>
            </SelectContent>
          </Select>
          <p v-if="campos.categoria_sanitaria_id" class="text-xs text-destructive">{{ campos.categoria_sanitaria_id }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="prod-nome">Nome</Label>
          <Input id="prod-nome" v-model.trim="formProd.nome" required maxlength="100" :aria-invalid="!!campos.nome" />
          <p v-if="campos.nome" class="text-xs text-destructive">{{ campos.nome }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="prod-dias">Carência da bula (dias)</Label>
          <Input id="prod-dias" v-model.number="formProd.dias" type="number" min="0" max="3650"
            :placeholder="diasDaCategoria(formProd.categoria) != null ? `Usar a da categoria: ${diasDaCategoria(formProd.categoria)}` : 'Sem sugestão'"
            :aria-invalid="!!campos.dias_carencia" />
          <p v-if="campos.dias_carencia" class="text-xs text-destructive">{{ campos.dias_carencia }}</p>
          <p class="text-xs text-muted-foreground">Use o maior prazo entre leite e carne.</p>
        </div>
        <label v-if="prodEditando" class="flex items-center gap-2 text-sm">
          <input v-model="formProd.ativo" type="checkbox" class="size-4 accent-primary" /> Produto ativo
        </label>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="prodAberto = false">Cancelar</Button>
        <Button type="submit" form="form-produto-sanitario" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
