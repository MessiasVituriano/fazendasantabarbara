<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { CircleCheck, TrendingDown, TrendingUp, TriangleAlert } from '@lucide/vue'
import { Badge } from '@/components/ui/badge'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Toggle } from '@/components/ui/toggle'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import AvatarAnimal from '../components/AvatarAnimal.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { dataBr, idade, numero } from '../formatos'

/** RF04 — animais de corte para abate, com bloqueio por carência sanitária. */
const router = useRouter()
const CATEGORIAS = [
  { value: 'BOI_GORDO', label: 'Boi gordo' },
  { value: 'GARROTE', label: 'Garrote' },
  { value: 'NOVILHA', label: 'Novilha' },
  { value: 'TOURO', label: 'Touro' },
]
const categoria = ref('BOI_GORDO')
const ocultar = ref(false)
const animais = ref([])
const meta = ref({ total: 0, em_carencia: 0 })
const carregando = ref(false)
const erros = ref([])

async function carregar() {
  carregando.value = true
  try {
    const { data } = await http.get('/manejo/abate', { params: { 'categoria[]': categoria.value, ocultar_em_carencia: ocultar.value ? 1 : 0 } })
    animais.value = data.data
    meta.value = data.meta
    erros.value = []
  } catch (e) {
    erros.value = e.mensagens
  } finally {
    carregando.value = false
  }
}
watch([categoria, ocultar], carregar, { immediate: true })
const liberados = computed(() => meta.value.total - meta.value.em_carencia)
/** Previsão para o peso alvo: "Já atingiu", a data, ou o motivo de não haver previsão. */
const previsao = (c) => (!c ? '—' : c.previsao_alvo?.dias === 0 ? 'Já atingiu' : c.previsao_alvo ? dataBr(c.previsao_alvo.data) : c.situacao_tendencia === 'INSUFICIENTE' ? 'Poucas pesagens' : 'Não atinge no ritmo')
const prontos30 = computed(() => animais.value.filter((a) => a.crescimento?.previsao_alvo && a.crescimento.previsao_alvo.dias <= 30 && !a.em_carencia).length)
</script>

<template>
  <CabecalhoPagina titulo="Abate" :contador="meta.total">
    <template #filtros>
      <Select v-model="categoria">
        <SelectTrigger class="w-44"><SelectValue /></SelectTrigger>
        <SelectContent><SelectItem v-for="c in CATEGORIAS" :key="c.value" :value="c.value">{{ c.label }}</SelectItem></SelectContent>
      </Select>
      <Toggle v-model="ocultar" variant="outline" aria-label="Omitir animais em carência">
        <TriangleAlert /> Omitir em carência
      </Toggle>
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erros" />

    <div class="grid gap-4 sm:grid-cols-3">
      <Card class="gap-1">
        <CardHeader>
          <CardDescription class="flex items-center gap-1.5"><CircleCheck class="size-4 text-emerald-600 dark:text-emerald-400" /> Liberados para abate</CardDescription>
          <CardTitle class="text-3xl tabular-nums">{{ liberados }}</CardTitle>
        </CardHeader>
      </Card>
      <Card class="gap-1" :class="{ 'border-destructive/40 bg-destructive/5': meta.em_carencia }">
        <CardHeader>
          <CardDescription class="flex items-center gap-1.5"><TriangleAlert class="size-4 text-destructive" /> Em carência (bloqueados)</CardDescription>
          <CardTitle class="text-3xl tabular-nums" :class="{ 'text-destructive': meta.em_carencia }">{{ meta.em_carencia }}</CardTitle>
        </CardHeader>
      </Card>
      <Card class="gap-1">
        <CardHeader>
          <CardDescription>No peso alvo em até 30 dias (liberados)</CardDescription>
          <CardTitle class="text-3xl tabular-nums">{{ prontos30 }}</CardTitle>
        </CardHeader>
      </Card>
    </div>

    <!-- Celular: lista compacta, com a situação sanitária sempre visível -->
    <ul class="divide-y overflow-hidden rounded-xl border bg-card sm:hidden" :class="{ 'opacity-50': carregando }">
      <li v-for="a in animais" :key="a.id" :class="{ 'bg-destructive/5': a.em_carencia }">
        <RouterLink :to="`/animais/${a.id}`" class="flex items-center gap-3 p-3">
          <AvatarAnimal :animal="a" :tamanho="44" />
          <div class="min-w-0 flex-1 space-y-1">
            <p class="truncate font-medium">{{ a.nome || a.brinco }}<span v-if="a.nome" class="ml-2 font-mono text-xs font-normal text-muted-foreground">{{ a.brinco }}</span></p>
            <p class="truncate text-xs text-muted-foreground">{{ idade(a.data_nascimento) }} · {{ a.aptidao_label }} · {{ a.piquete_atual ? `Piquete ${a.piquete_atual.identificacao}` : 'Sem piquete' }}</p>
            <p v-if="a.crescimento" class="text-xs tabular-nums">
              {{ numero(a.crescimento.peso_atual, 0) }} kg · {{ a.crescimento.gmd !== null ? `${numero(a.crescimento.gmd, 2)} kg/dia` : 'sem tendência' }} ·
              {{ numero(a.crescimento.alvo_kg, 0) }} kg: {{ previsao(a.crescimento) }}
            </p>
            <Badge v-if="a.alerta" variant="destructive" :title="a.alerta.mensagem"><TriangleAlert /> Carência até {{ dataBr(a.carencia_ate) }}</Badge>
            <Badge v-else variant="outline" class="text-emerald-700 dark:text-emerald-400"><CircleCheck /> Liberado</Badge>
          </div>
        </RouterLink>
      </li>
      <li v-if="!animais.length && !carregando" class="py-10 text-center text-sm text-muted-foreground">Nenhum animal nesta lista.</li>
    </ul>

    <div class="hidden overflow-hidden rounded-xl border bg-card sm:block" :class="{ 'opacity-50': carregando }">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Animal</TableHead><TableHead>Idade</TableHead><TableHead>Aptidão</TableHead>
            <TableHead>Piquete</TableHead><TableHead class="text-right">Peso</TableHead><TableHead class="text-right">GMD</TableHead>
            <TableHead>Peso alvo</TableHead><TableHead>Situação sanitária</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="a in animais" :key="a.id" class="cursor-pointer" :class="{ 'bg-destructive/5': a.em_carencia }" @click="router.push(`/animais/${a.id}`)">
            <TableCell>
              <div class="flex items-center gap-3">
                <AvatarAnimal :animal="a" :tamanho="36" />
                <div><p class="font-medium">{{ a.nome || a.brinco }}</p><p class="font-mono text-xs text-muted-foreground">{{ a.brinco }}</p></div>
              </div>
            </TableCell>
            <TableCell>{{ idade(a.data_nascimento) }}</TableCell>
            <TableCell>{{ a.aptidao_label }}</TableCell>
            <TableCell>{{ a.piquete_atual?.identificacao ?? '—' }}</TableCell>
            <TableCell class="text-right tabular-nums">{{ a.crescimento?.peso_atual != null ? `${numero(a.crescimento.peso_atual, 0)} kg` : '—' }}</TableCell>
            <TableCell class="text-right tabular-nums whitespace-nowrap">
              <span v-if="a.crescimento?.gmd != null" class="inline-flex items-center gap-1" :class="{ 'text-destructive': a.crescimento.gmd <= 0 }">
                <component :is="a.crescimento.gmd > 0 ? TrendingUp : TrendingDown" class="size-3.5" />{{ numero(a.crescimento.gmd, 2) }}
              </span>
              <span v-else class="text-muted-foreground">—</span>
            </TableCell>
            <TableCell>
              <p class="tabular-nums">{{ previsao(a.crescimento) }}</p>
              <p v-if="a.crescimento" class="text-xs text-muted-foreground">{{ numero(a.crescimento.alvo_kg, 0) }} kg · {{ a.crescimento.alvo_origem === 'META_LOTE' ? `meta do ${a.crescimento.lote.nome}` : 'peso de abate padrão' }}</p>
            </TableCell>
            <TableCell>
              <Badge v-if="a.alerta" variant="destructive" :title="a.alerta.mensagem"><TriangleAlert /> Carência até {{ dataBr(a.carencia_ate) }}</Badge>
              <Badge v-else variant="outline" class="text-emerald-700 dark:text-emerald-400"><CircleCheck /> Liberado</Badge>
            </TableCell>
          </TableRow>
          <TableRow v-if="!animais.length && !carregando">
            <TableCell colspan="8" class="py-10 text-center text-muted-foreground">Nenhum animal nesta lista.</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>
  </main>
</template>
