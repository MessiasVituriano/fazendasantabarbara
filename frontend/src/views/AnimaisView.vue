<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useLocalStorage } from '@vueuse/core'
import { Check, ChevronLeft, ChevronRight, LayoutGrid, List, Plus, Search, SearchX, TriangleAlert } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import http from '../api/http'
import AvatarAnimal from '../components/AvatarAnimal.vue'
import CartaoAnimal from '../components/CartaoAnimal.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { corReprodutivo } from '../components/cores'
import { useEnvio } from '../composables/useEnvio'
import { useOpcoes } from '../composables/useOpcoes'
import { useSessao } from '../composables/useSessao'
import { idade } from '../formatos'

const router = useRouter()
const { ehGestor } = useSessao()
const { opcoes } = useOpcoes()
const itens = computed(() => opcoes.value ?? {})

// Selects do Reka UI não aceitam '' como valor: "todos" representa sem filtro.
const TODOS = 'todos'
const filtros = reactive({ busca: '', categoria: TODOS, aptidao: TODOS, sexo: TODOS, status_geral: 'ATIVO' })
const seletores = computed(() => [
  { chave: 'categoria', todos: 'Todas as categorias', lista: itens.value.categoria, classe: 'w-48' },
  { chave: 'aptidao', todos: 'Todas as aptidões', lista: itens.value.aptidao, classe: 'w-44' },
  { chave: 'sexo', todos: 'Machos e fêmeas', lista: itens.value.sexo, classe: 'w-44' },
])
const pagina = ref(1)
const POR_PAGINA = 24
const animais = ref([])
const meta = ref({ total: 0, last_page: 1 })
const carregando = ref(false)
const modo = useLocalStorage('rebanho.modo', 'cartoes')

async function carregar() {
  carregando.value = true
  try {
    const params = { page: pagina.value, por_pagina: POR_PAGINA }
    for (const [k, v] of Object.entries(filtros)) if (v && v !== TODOS) params[k] = v
    const { data } = await http.get('/animais', { params })
    animais.value = data.data
    meta.value = data.meta
  } catch (e) {
    toast.error('Não foi possível carregar o rebanho', { description: e.mensagens?.[0] })
  } finally {
    carregando.value = false
  }
}
let atraso
watch(filtros, () => {
  clearTimeout(atraso)
  atraso = setTimeout(() => { pagina.value = 1; carregar() }, 250)
})
watch(pagina, carregar)
carregar()

// Cadastro
const aberto = ref(false)
const vazio = () => ({ brinco: '', nome: '', sexo: 'F', data_nascimento: '', aptidao: 'LEITE', categoria: 'NOVILHA' })
const novo = ref(vazio())
const { enviando, mensagens, campos, enviar } = useEnvio()
async function salvar() {
  try {
    const { data } = await enviar(() => http.post('/animais', { ...novo.value, nome: novo.value.nome || null }))
    aberto.value = false
    novo.value = vazio()
    toast.success('Animal cadastrado', { description: data.data.brinco })
    carregar()
  } catch { /* erros exibidos no formulário */ }
}
</script>

<template>
  <CabecalhoPagina titulo="Rebanho" :contador="meta.total">
    <template #acoes>
      <div class="inline-flex rounded-md border p-0.5" role="group" aria-label="Modo de exibição">
        <Button size="icon" class="size-7" :variant="modo === 'cartoes' ? 'secondary' : 'ghost'" aria-label="Cartões" @click="modo = 'cartoes'"><LayoutGrid /></Button>
        <Button size="icon" class="size-7" :variant="modo === 'tabela' ? 'secondary' : 'ghost'" aria-label="Tabela" @click="modo = 'tabela'"><List /></Button>
      </div>
      <Button v-if="ehGestor" @click="aberto = true"><Plus /> <span class="hidden sm:inline">Novo animal</span></Button>
    </template>
    <template #filtros>
      <div class="relative w-full sm:w-56">
        <Search class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input v-model="filtros.busca" placeholder="Buscar brinco ou nome" class="pl-8" />
      </div>
      <Select v-for="s in seletores" :key="s.chave" v-model="filtros[s.chave]">
        <SelectTrigger :class="s.classe"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem :value="TODOS">{{ s.todos }}</SelectItem>
          <SelectItem v-for="o in s.lista" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
        </SelectContent>
      </Select>
      <Select v-model="filtros.status_geral">
        <SelectTrigger class="w-32"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectItem v-for="o in itens.status_geral" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
        </SelectContent>
      </Select>
    </template>
  </CabecalhoPagina>

  <main class="px-4 py-6 md:px-6" :class="{ 'opacity-50': carregando }">
    <div v-if="modo === 'cartoes'" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
      <CartaoAnimal v-for="a in animais" :key="a.id" :animal="a" />
    </div>

    <div v-else class="overflow-hidden rounded-xl border bg-card">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Animal</TableHead><TableHead>Sexo</TableHead><TableHead>Idade</TableHead><TableHead>Aptidão</TableHead>
            <TableHead>Categoria</TableHead><TableHead>Reprodutivo</TableHead><TableHead>Piquete</TableHead><TableHead />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="a in animais" :key="a.id" class="cursor-pointer" @click="router.push(`/animais/${a.id}`)">
            <TableCell>
              <div class="flex items-center gap-3">
                <AvatarAnimal :animal="a" :tamanho="36" />
                <div>
                  <p class="font-medium">{{ a.nome || a.brinco }}</p>
                  <p class="font-mono text-xs text-muted-foreground">{{ a.brinco }}</p>
                </div>
              </div>
            </TableCell>
            <TableCell>{{ a.sexo_label }}</TableCell>
            <TableCell>{{ idade(a.data_nascimento) }}</TableCell>
            <TableCell>{{ a.aptidao_label }}</TableCell>
            <TableCell><Badge variant="secondary">{{ a.categoria_label }}</Badge></TableCell>
            <TableCell>
              <Badge v-if="a.sexo === 'F'" class="border-transparent" :class="corReprodutivo[a.status_reprodutivo]">{{ a.status_reprodutivo_label }}</Badge>
              <span v-else class="text-muted-foreground">—</span>
            </TableCell>
            <TableCell>{{ a.piquete_atual?.identificacao ?? '—' }}</TableCell>
            <TableCell>
              <Badge v-if="a.em_carencia" variant="destructive"><TriangleAlert /> Carência</Badge>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <div v-if="!animais.length && !carregando" class="flex flex-col items-center gap-2 py-16 text-center">
      <SearchX class="size-8 text-muted-foreground" />
      <p class="font-medium">Nenhum animal encontrado</p>
      <p class="text-sm text-muted-foreground">Ajuste os filtros ou cadastre um novo animal.</p>
    </div>

    <div v-if="meta.last_page > 1" class="mt-6 flex items-center justify-end gap-2 text-sm text-muted-foreground">
      <span>Página {{ meta.current_page }} de {{ meta.last_page }}</span>
      <Button variant="outline" size="icon" :disabled="pagina <= 1" aria-label="Anterior" @click="pagina--"><ChevronLeft /></Button>
      <Button variant="outline" size="icon" :disabled="pagina >= meta.last_page" aria-label="Próxima" @click="pagina++"><ChevronRight /></Button>
    </div>
  </main>

  <Dialog v-model:open="aberto">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>Novo animal</DialogTitle>
        <DialogDescription>Cadastre um animal no rebanho.</DialogDescription>
      </DialogHeader>
      <p v-if="mensagens.length && !Object.keys(campos).length" class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">{{ mensagens[0] }}</p>
      <form id="form-animal" class="grid gap-4 sm:grid-cols-2" @submit.prevent="salvar">
        <div class="grid gap-1.5">
          <Label for="brinco">Brinco *</Label>
          <Input id="brinco" v-model.trim="novo.brinco" :aria-invalid="!!campos.brinco" />
          <p v-if="campos.brinco" class="text-xs text-destructive">{{ campos.brinco }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="nome">Nome</Label>
          <Input id="nome" v-model.trim="novo.nome" />
        </div>
        <div class="grid gap-1.5">
          <Label>Sexo *</Label>
          <Select v-model="novo.sexo"><SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem v-for="o in itens.sexo" :key="o.value" :value="o.value">{{ o.label }}</SelectItem></SelectContent></Select>
        </div>
        <div class="grid gap-1.5">
          <Label for="nasc">Data de nascimento *</Label>
          <Input id="nasc" v-model="novo.data_nascimento" type="date" :aria-invalid="!!campos.data_nascimento" />
          <p v-if="campos.data_nascimento" class="text-xs text-destructive">{{ campos.data_nascimento }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label>Aptidão *</Label>
          <Select v-model="novo.aptidao"><SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem v-for="o in itens.aptidao" :key="o.value" :value="o.value">{{ o.label }}</SelectItem></SelectContent></Select>
        </div>
        <div class="grid gap-1.5">
          <Label>Categoria *</Label>
          <Select v-model="novo.categoria"><SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem v-for="o in itens.categoria" :key="o.value" :value="o.value">{{ o.label }}</SelectItem></SelectContent></Select>
        </div>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="aberto = false">Cancelar</Button>
        <Button type="submit" form="form-animal" :disabled="enviando"><Check /> Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
