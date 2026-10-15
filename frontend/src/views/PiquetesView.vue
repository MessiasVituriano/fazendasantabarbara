<script setup>
import { computed, ref } from 'vue'
import { LogIn, LogOut, Plus, Users } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import AvatarAnimal from '../components/AvatarAnimal.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { useEnvio } from '../composables/useEnvio'
import { useOpcoes } from '../composables/useOpcoes'
import { useSessao } from '../composables/useSessao'
import { useDataMinima } from '../composables/useDataMinima'
import { dataBr, hoje, numero } from '../formatos'

/** RN03 — painel de piquetes em cards: status e animais alocados no momento. */
const { opcoes } = useOpcoes()
const { ehGestor } = useSessao()
const dataMinima = useDataMinima()
const piquetes = ref([])
const erroCarga = ref([])
async function carregar() {
  try { piquetes.value = (await http.get('/piquetes')).data.data } catch (e) { erroCarga.value = e.mensagens }
}
carregar()

const ESTILO = {
  OCUPADO: { borda: 'border-l-emerald-600', selo: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' },
  DESCANSO: { borda: 'border-l-amber-500', selo: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
  DEGRADADO: { borda: 'border-l-red-600', selo: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
}
const rotuloStatus = (s) => opcoes.value?.status_piquete.find((o) => o.value === s)?.label ?? s
const { enviando, mensagens, campos, enviar } = useEnvio()

// Novo piquete
const novoAberto = ref(false)
const novo = ref({ identificacao: '', hectares: '' })
async function criar() {
  try {
    await enviar(() => http.post('/piquetes', { ...novo.value, hectares: String(novo.value.hectares).replace(',', '.') }))
    toast.success(`Piquete ${novo.value.identificacao} criado`)
    novoAberto.value = false
    novo.value = { identificacao: '', hectares: '' }
    carregar()
  } catch { /* erros no formulário */ }
}

// Detalhe / movimentação
const aberto = ref(null)
const disponiveis = ref([])
const selecionados = ref([])
const dataMov = ref(hoje())
const buscaAnimal = ref('')
const filtrados = computed(() => {
  const q = buscaAnimal.value.trim().toLowerCase()
  return q ? disponiveis.value.filter((a) => `${a.brinco} ${a.nome ?? ''}`.toLowerCase().includes(q)) : disponiveis.value
})

async function abrir(p) {
  mensagens.value = []
  selecionados.value = []
  buscaAnimal.value = ''
  dataMov.value = hoje()
  const [det, animais] = await Promise.all([http.get(`/piquetes/${p.id}`), http.get('/animais', { params: { por_pagina: 200 } })])
  aberto.value = det.data.data
  const presentes = new Set(aberto.value.ocupacoes_atuais.map((o) => o.animal_id))
  disponiveis.value = animais.data.data.filter((a) => !presentes.has(a.id))
}
async function mover(acao, extra, msg) {
  try {
    const { data } = await enviar(() => http.post(`/piquetes/${aberto.value.id}/${acao}`, extra))
    toast.success(msg)
    await abrir(data.data)
    carregar()
  } catch { /* exibido no diálogo */ }
}
const entrar = () => mover('entradas', { animal_ids: selecionados.value, data_entrada: dataMov.value }, `${selecionados.value.length} animal(is) movido(s) para ${aberto.value.identificacao}`)
const retirar = (ids) => mover('saidas', { data_saida: dataMov.value, ...(ids ? { animal_ids: ids } : {}) }, 'Animal(is) retirado(s)')
async function mudarStatus(status) {
  try {
    const { data } = await enviar(() => http.patch(`/piquetes/${aberto.value.id}`, { status }))
    aberto.value = data.data
    carregar()
  } catch { /* exibido no diálogo */ }
}
</script>

<template>
  <CabecalhoPagina titulo="Piquetes" :contador="piquetes.length">
    <template v-if="ehGestor" #acoes><Button @click="novoAberto = true"><Plus /> <span class="hidden sm:inline">Novo piquete</span></Button></template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erroCarga" />
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      <button v-for="p in piquetes" :key="p.id" class="rounded-xl text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50" @click="abrir(p).catch(() => {})">
        <Card class="h-full border-l-4 transition hover:shadow-md" :class="ESTILO[p.status]?.borda">
          <CardHeader>
            <div class="flex items-start justify-between gap-2">
              <CardTitle class="text-lg">{{ p.identificacao }}</CardTitle>
              <Badge class="border-transparent" :class="ESTILO[p.status]?.selo">{{ rotuloStatus(p.status) }}</Badge>
            </div>
            <CardDescription>{{ numero(p.hectares) }} ha</CardDescription>
          </CardHeader>
          <CardContent>
            <p class="text-3xl font-semibold tabular-nums">{{ p.animais_alocados }}</p>
            <p class="flex items-center gap-1 text-xs text-muted-foreground"><Users class="size-3.5" /> animais alocados</p>
            <p v-if="p.animais_alocados" class="mt-1 text-xs text-muted-foreground">Lotação: {{ numero(p.animais_alocados / p.hectares, 1) }} cab/ha</p>
          </CardContent>
        </Card>
      </button>
    </div>
    <p v-if="!piquetes.length" class="text-sm text-muted-foreground">Nenhum piquete cadastrado.</p>
  </main>

  <Dialog v-model:open="novoAberto">
    <DialogContent class="sm:max-w-md">
      <DialogHeader><DialogTitle>Novo piquete</DialogTitle><DialogDescription>Identificação e área do piquete.</DialogDescription></DialogHeader>
      <form id="form-piquete" class="grid gap-4 sm:grid-cols-2" @submit.prevent="criar">
        <div class="grid gap-1.5">
          <Label for="ident">Identificação</Label>
          <Input id="ident" v-model.trim="novo.identificacao" required :aria-invalid="!!campos.identificacao" />
          <p v-if="campos.identificacao" class="text-xs text-destructive">{{ campos.identificacao }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="ha">Hectares</Label>
          <Input id="ha" v-model="novo.hectares" inputmode="decimal" required :aria-invalid="!!campos.hectares" />
        </div>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="novoAberto = false">Cancelar</Button>
        <Button type="submit" form="form-piquete" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <Dialog :open="!!aberto" @update:open="(v) => !v && (aberto = null)">
    <DialogContent v-if="aberto" class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>Piquete {{ aberto.identificacao }}</DialogTitle>
        <DialogDescription>{{ numero(aberto.hectares) }} ha · {{ aberto.ocupacoes_atuais.length }} animal(is)</DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />

      <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-1.5">
          <Label>Status</Label>
          <Select :model-value="aberto.status" :disabled="!ehGestor" @update:model-value="mudarStatus">
            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem v-for="s in opcoes?.status_piquete" :key="s.value" :value="s.value">{{ s.label }}</SelectItem></SelectContent>
          </Select>
        </div>
        <div class="grid gap-1.5">
          <Label for="data-mov">Data da movimentação</Label>
          <Input id="data-mov" v-model="dataMov" type="date" :min="dataMinima" :max="hoje()" />
        </div>
      </div>

      <div>
        <div class="mb-2 flex items-center justify-between">
          <h3 class="text-sm font-medium">No piquete ({{ aberto.ocupacoes_atuais.length }})</h3>
          <Button v-if="aberto.ocupacoes_atuais.length" variant="ghost" size="sm" class="text-destructive" @click="retirar()"><LogOut /> Retirar todos</Button>
        </div>
        <ul class="max-h-44 divide-y overflow-y-auto rounded-md border text-sm">
          <li v-for="o in aberto.ocupacoes_atuais" :key="o.id" class="flex items-center justify-between px-3 py-1.5">
            <span>{{ o.animal.brinco }} <span class="text-muted-foreground">desde {{ dataBr(o.data_entrada) }}</span></span>
            <Button variant="ghost" size="sm" class="h-7 text-destructive" @click="retirar([o.animal_id])">Retirar</Button>
          </li>
          <li v-if="!aberto.ocupacoes_atuais.length" class="px-3 py-2 text-muted-foreground">Vazio</li>
        </ul>
      </div>

      <div v-if="aberto.status !== 'DEGRADADO'">
        <h3 class="mb-2 text-sm font-medium">Mover animais para cá</h3>
        <Input v-model="buscaAnimal" placeholder="Buscar animal" class="mb-2" />
        <ul class="max-h-48 divide-y overflow-y-auto rounded-md border text-sm">
          <li v-for="a in filtrados" :key="a.id">
            <label class="flex cursor-pointer items-center gap-3 px-3 py-1.5 hover:bg-accent">
              <input v-model="selecionados" type="checkbox" :value="a.id" class="size-4 accent-primary" />
              <AvatarAnimal :animal="a" :tamanho="24" />
              <span class="flex-1">{{ a.nome || a.brinco }} <span class="text-muted-foreground">· {{ a.categoria_label }}</span></span>
              <span v-if="a.piquete_atual" class="text-xs text-muted-foreground">em {{ a.piquete_atual.identificacao }}</span>
            </label>
          </li>
        </ul>
      </div>

      <DialogFooter v-if="aberto.status !== 'DEGRADADO'">
        <Button :disabled="!selecionados.length || enviando" @click="entrar"><LogIn /> Mover {{ selecionados.length || '' }} animal(is)</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
