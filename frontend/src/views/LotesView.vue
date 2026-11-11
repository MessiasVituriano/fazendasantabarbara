<script setup>
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ListFilter, Plus } from '@lucide/vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import FormLote from '../components/FormLote.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { dataBr, moeda, numero } from '../formatos'

/**
 * Lotes de recria/engorda: animais, peso médio, GMD, meta e custos de cada lote.
 * O detalhe (engorda com projeção e custos) abre na página do lote (/lotes/:id).
 */
const route = useRoute()
const router = useRouter()
const TOM_META = {
  NO_RITMO: 'text-emerald-700 dark:text-emerald-400', ATENCAO: 'text-amber-700 dark:text-amber-400',
  ATRASADA: 'text-destructive', INVIAVEL: 'text-destructive', VENCIDA: 'text-destructive',
  ATINGIDA: 'text-sky-700 dark:text-sky-400', SEM_DADOS: 'text-muted-foreground',
}
// Endereço antigo (/lotes?lote=ID): vai direto para a página do lote.
if (route.query.lote) router.replace(`/lotes/${route.query.lote}`)

const lotes = ref([])
const incluirEncerrados = ref(false)
const erroCarga = ref([])

async function carregar() {
  try {
    lotes.value = (await http.get('/lotes', { params: { incluir_encerrados: incluirEncerrados.value ? 1 : 0 } })).data.data
    erroCarga.value = []
  } catch (e) { erroCarga.value = e.mensagens }
}
watch(incluirEncerrados, carregar, { immediate: true })

const formAberto = ref(false)
</script>

<template>
  <CabecalhoPagina titulo="Lotes" :contador="lotes.length">
    <template #acoes>
      <Button variant="outline" :class="{ 'border-primary text-primary': incluirEncerrados }" @click="incluirEncerrados = !incluirEncerrados">
        <ListFilter /> <span class="hidden sm:inline">{{ incluirEncerrados ? 'Mostrando encerrados' : 'Incluir encerrados' }}</span>
      </Button>
      <Button @click="formAberto = true"><Plus /> <span class="hidden sm:inline">Novo lote</span></Button>
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erroCarga" />
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <RouterLink v-for="l in lotes" :key="l.id" :to="`/lotes/${l.id}`" class="rounded-xl text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
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
            <p v-if="l.engorda && l.engorda.peso_medio === null" class="col-span-2 text-xs text-muted-foreground">{{ l.animais }} animal(is) · sem pesagens recentes</p>
            <template v-else-if="l.engorda">
              <div>
                <p class="text-xs text-muted-foreground">{{ l.animais }} animal(is) · peso médio</p>
                <p class="font-semibold tabular-nums">{{ numero(l.engorda.peso_medio, 0) }} kg</p>
              </div>
              <div>
                <p class="text-xs text-muted-foreground">GMD médio</p>
                <p class="font-semibold tabular-nums">{{ numero(l.engorda.gmd_medio, 3) }} kg/dia</p>
              </div>
              <div v-if="l.engorda.meta" class="col-span-2 space-y-1">
                <p class="flex justify-between gap-2 text-xs">
                  <span class="text-muted-foreground">Meta {{ numero(l.engorda.meta.peso_alvo_kg, 0) }} kg até {{ dataBr(l.engorda.meta.data_alvo) }}</span>
                  <span class="font-medium" :class="TOM_META[l.engorda.meta.situacao]">{{ l.engorda.meta.situacao_label }}</span>
                </p>
                <div v-if="l.engorda.meta.progresso !== null" class="h-1.5 overflow-hidden rounded-full bg-muted">
                  <div class="h-full rounded-full bg-primary" :style="{ width: `${l.engorda.meta.progresso * 100}%` }" />
                </div>
              </div>
            </template>
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
      </RouterLink>
    </div>
    <p v-if="!lotes.length" class="text-sm text-muted-foreground">Nenhum lote {{ incluirEncerrados ? '' : 'ativo ' }}cadastrado.</p>
  </main>

  <FormLote v-model:open="formAberto" @salvo="(l) => router.push(`/lotes/${l.id}`)" />
</template>
