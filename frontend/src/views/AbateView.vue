<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { CircleCheck, TriangleAlert } from '@lucide/vue'
import { Badge } from '@/components/ui/badge'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Toggle } from '@/components/ui/toggle'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import AvatarAnimal from '../components/AvatarAnimal.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { dataBr, idade } from '../formatos'

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

    <div class="grid gap-4 sm:grid-cols-2">
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
    </div>

    <div class="overflow-hidden rounded-xl border bg-card" :class="{ 'opacity-50': carregando }">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Animal</TableHead><TableHead>Idade</TableHead><TableHead>Aptidão</TableHead>
            <TableHead>Piquete</TableHead><TableHead>Situação sanitária</TableHead>
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
            <TableCell>
              <Badge v-if="a.alerta" variant="destructive" :title="a.alerta.mensagem"><TriangleAlert /> Carência até {{ dataBr(a.carencia_ate) }}</Badge>
              <Badge v-else variant="outline" class="text-emerald-700 dark:text-emerald-400"><CircleCheck /> Liberado</Badge>
            </TableCell>
          </TableRow>
          <TableRow v-if="!animais.length && !carregando">
            <TableCell colspan="5" class="py-10 text-center text-muted-foreground">Nenhum animal nesta lista.</TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>
  </main>
</template>
