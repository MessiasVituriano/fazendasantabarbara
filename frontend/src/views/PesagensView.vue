<script setup>
import { computed, ref } from 'vue'
import { Check, Search, TrendingDown, TrendingUp } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import AvatarAnimal from '../components/AvatarAnimal.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { useDataMinima } from '../composables/useDataMinima'
import { useOpcoes } from '../composables/useOpcoes'
import { dataBr, hoje, novoUuid, numero } from '../formatos'
import { avisarGuardadoOffline, enviarOuGuardar } from '../offline/fila'

/** Pesagem em lote do gado de corte, com último peso e prévia do GMD de cada animal. */
const data = ref(hoje())
const dataMinima = useDataMinima()
const { opcoes } = useOpcoes()
/** Ganho fora da faixa plausível entre a última pesagem e a digitada: provável erro de digitação. */
const suspeito = (gmd) => {
  const e = opcoes.value?.pecuaria?.engorda
  return gmd !== null && !!e && (gmd < e.gmd_suspeito_min || gmd > e.gmd_suspeito_max)
}
const linhas = ref([])
const busca = ref('')
const carregando = ref(false)
const salvando = ref(false)
const erros = ref([])
const errosLinha = ref({})

const paraNumero = (t) => {
  const limpo = String(t ?? '').trim().replace(/\./g, '').replace(',', '.')
  return limpo === '' ? null : Number(limpo)
}

async function carregar() {
  carregando.value = true
  try {
    const [animais, recentes] = await Promise.all([
      http.get('/animais', { params: { aptidao: ['CORTE', 'MISTA'], por_pagina: 200 } }).then((r) => r.data.data),
      http.get('/pesagens', { params: { por_pagina: 500 } }).then((r) => r.data.data),
    ])
    const ultima = {}
    for (const p of recentes) if (!ultima[p.animal_id]) ultima[p.animal_id] = p // lista já vem da mais recente
    linhas.value = animais.map((animal) => ({ animal, valor: '', ultima: ultima[animal.id] ?? null }))
  } catch (e) {
    erros.value = e.mensagens
  } finally {
    carregando.value = false
  }
}
carregar()

const visiveis = computed(() => {
  const q = busca.value.trim().toLowerCase()
  return q ? linhas.value.filter((l) => `${l.animal.brinco} ${l.animal.nome ?? ''}`.toLowerCase().includes(q)) : linhas.value
})
const preenchidas = computed(() => linhas.value.filter((l) => paraNumero(l.valor) !== null))

/** Prévia do GMD (kg/dia) entre a última pesagem e o peso digitado. */
function gmdPrevia(l) {
  const peso = paraNumero(l.valor)
  if (peso === null || !l.ultima) return null
  const dias = Math.round((new Date(`${data.value}T12:00:00`) - new Date(`${l.ultima.data_pesagem}T12:00:00`)) / 86400000)
  return dias > 0 ? (peso - l.ultima.peso_kg) / dias : null
}

async function salvar() {
  salvando.value = true
  erros.value = []
  errosLinha.value = {}
  const enviadas = preenchidas.value
  try {
    const { offline, resposta } = await enviarOuGuardar({
      metodo: 'post',
      url: '/pesagens',
      dados: enviadas.map((l) => ({ uuid: novoUuid(), animal_id: l.animal.id, data_pesagem: data.value, peso_kg: paraNumero(l.valor) })),
      descricao: `Pesagem de ${dataBr(data.value)} (${enviadas.length} animal(is))`,
    })
    if (offline) avisarGuardadoOffline(`Pesagem de ${dataBr(data.value)}`)
    else toast.success(`Pesagem de ${dataBr(data.value)} salva`, { description: `${resposta.data.meta.criados} animal(is) pesado(s)` })
    await carregar()
  } catch (e) {
    erros.value = e.mensagens
    for (const [campo, msg] of Object.entries(e.campos ?? {})) {
      const m = campo.match(/^registros\.(\d+)\./)
      if (m) errosLinha.value[enviadas[Number(m[1])].animal.id] = msg
    }
  } finally {
    salvando.value = false
  }
}
</script>

<template>
  <CabecalhoPagina titulo="Pesagens">
    <template #acoes>
      <Input v-model="data" type="date" :min="dataMinima" :max="hoje()" class="h-9 w-40" aria-label="Data da pesagem" />
    </template>
    <template #filtros>
      <div class="relative w-full sm:w-64">
        <Search class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input v-model="busca" placeholder="Buscar animal" class="pl-8" />
      </div>
      <p class="ml-auto text-sm text-muted-foreground"><b class="text-foreground tabular-nums">{{ preenchidas.length }}</b> de {{ linhas.length }} animais pesados</p>
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erros" />
    <Card class="gap-0 overflow-hidden py-0" :class="{ 'opacity-50': carregando }">
      <ul class="divide-y">
        <li v-for="l in visiveis" :key="l.animal.id" class="grid grid-cols-[auto_1fr_auto] items-center gap-x-3 gap-y-1 px-4 py-2.5 sm:grid-cols-[auto_1fr_10rem_9rem_7rem]">
          <AvatarAnimal :animal="l.animal" :tamanho="40" />
          <div class="min-w-0">
            <p class="truncate font-medium">{{ l.animal.nome || l.animal.brinco }}</p>
            <p class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
              <span class="font-mono">{{ l.animal.brinco }}</span>
              <Badge variant="secondary" class="px-1.5 py-0">{{ l.animal.categoria_label }}</Badge>
            </p>
          </div>
          <p class="hidden text-right text-xs text-muted-foreground sm:block">
            <template v-if="l.ultima">Último: <span class="tabular-nums">{{ numero(l.ultima.peso_kg, 1) }} kg</span><br />em {{ dataBr(l.ultima.data_pesagem) }}</template>
            <template v-else>Primeira pesagem</template>
          </p>
          <div class="w-28 sm:w-auto">
            <div class="relative">
              <Input v-model="l.valor" inputmode="decimal" autocomplete="off" placeholder="0,0" class="pr-8 text-right tabular-nums"
                     :aria-label="`Peso de ${l.animal.brinco}`" :aria-invalid="!!errosLinha[l.animal.id]" />
              <span class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-xs text-muted-foreground">kg</span>
            </div>
            <p v-if="errosLinha[l.animal.id]" class="mt-1 text-xs text-destructive">{{ errosLinha[l.animal.id] }}</p>
          </div>
          <p class="col-span-3 text-right text-xs sm:col-span-1">
            <span v-if="gmdPrevia(l) !== null" class="inline-flex items-center gap-1 font-medium"
                  :class="gmdPrevia(l) >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-destructive'">
              <component :is="gmdPrevia(l) >= 0 ? TrendingUp : TrendingDown" class="size-3.5" />
              {{ numero(gmdPrevia(l), 3) }} kg/dia
            </span>
            <span v-if="suspeito(gmdPrevia(l))" class="block font-medium text-destructive">Confira o peso</span>
          </p>
        </li>
        <li v-if="!visiveis.length && !carregando" class="px-4 py-10 text-center text-sm text-muted-foreground">Nenhum animal de corte encontrado.</li>
      </ul>
    </Card>
  </main>

  <div v-if="preenchidas.length" class="sticky bottom-0 z-30 border-t bg-background/95 backdrop-blur">
    <div class="flex items-center justify-end gap-3 px-4 py-3 md:px-6">
      <span class="mr-auto text-sm text-muted-foreground">{{ preenchidas.length }} peso(s) a salvar</span>
      <Button :disabled="salvando" @click="salvar"><Check /> {{ salvando ? 'Salvando…' : 'Salvar pesagem' }}</Button>
    </div>
  </div>
</template>
