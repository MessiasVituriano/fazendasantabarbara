<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { Check, CircleDot, Search, Sun, Sunset, TriangleAlert, Undo2 } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import AvatarAnimal from '../components/AvatarAnimal.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { turnoAtual } from '../composables/usePeriodoAtual'
import { useDataMinima } from '../composables/useDataMinima'
import { dataBr, hoje, numero } from '../formatos'

/**
 * Ordenha de um turno: cada vaca em lactação com seu campo de litros.
 * Abre no dia de hoje e no turno do relógio (Manhã antes das 12h, Tarde depois).
 * Valores já lançados vêm preenchidos para correção; apagar um campo remove o registro.
 */
const data = ref(hoje())
const dataMinima = useDataMinima()
const periodo = ref(turnoAtual())
const TURNOS = [{ value: 'MANHA', label: 'Manhã', icone: Sun }, { value: 'TARDE', label: 'Tarde', icone: Sunset }]

const linhas = ref([])          // { animal, valor (texto), original (texto), ontem (número|null) }
const carregando = ref(false)
const salvando = ref(false)
const busca = ref('')
const erros = ref([])
const errosLinha = ref({})       // animal_id -> mensagem
const entradas = ref({})         // animal_id -> elemento <input>

const texto = (v) => (v == null ? '' : Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 }))
const paraNumero = (t) => {
  const limpo = String(t ?? '').trim().replace(/\./g, '').replace(',', '.')
  return limpo === '' ? null : Number(limpo)
}
const diaAnterior = (d) => { const x = new Date(`${d}T12:00:00`); x.setDate(x.getDate() - 1); return x.toISOString().slice(0, 10) }

async function registrosDoTurno(dia) {
  const { data: r } = await http.get('/producoes-leite', {
    params: { data_inicio: dia, data_fim: dia, periodo: periodo.value, por_pagina: 500 },
  })
  return r.data
}

async function carregar() {
  carregando.value = true
  erros.value = []
  errosLinha.value = {}
  try {
    const [vacas, doTurno, deOntem] = await Promise.all([
      http.get('/manejo/ordenha').then((r) => r.data.data),
      registrosDoTurno(data.value),
      registrosDoTurno(diaAnterior(data.value)),
    ])
    const valor = Object.fromEntries(doTurno.map((p) => [p.animal_id, p.quantidade_litros]))
    const ontem = Object.fromEntries(deOntem.map((p) => [p.animal_id, Number(p.quantidade_litros)]))
    // Vacas que saíram da lactação mas têm registro neste turno continuam editáveis.
    const extras = doTurno.filter((p) => !vacas.some((v) => v.id === p.animal_id))
      .map((p) => ({ id: p.animal_id, brinco: p.animal.brinco, nome: p.animal.nome, sexo: 'F', aptidao: 'LEITE', categoria: 'SECA' }))

    linhas.value = [...vacas, ...extras].map((animal) => {
      const t = texto(valor[animal.id])
      return { animal, valor: t, original: t, ontem: ontem[animal.id] ?? null }
    })
  } catch (e) {
    erros.value = e.mensagens
  } finally {
    carregando.value = false
  }
}

// Troca de dia/turno com alterações pendentes pede confirmação.
const alteradas = computed(() => linhas.value.filter((l) => l.valor.trim() !== l.original.trim()))
const confirmarDescarte = () => !alteradas.value.length || confirm('Há valores não salvos neste turno. Descartar?')
let ignorarWatch = false
watch([data, periodo], ([d, p], [dAnt, pAnt]) => {
  if (ignorarWatch) return (ignorarWatch = false)
  if (!p) { ignorarWatch = true; periodo.value = pAnt; return } // turno sempre marcado
  if (!confirmarDescarte()) { ignorarWatch = true; data.value = dAnt; periodo.value = pAnt; return }
  carregar()
})
onBeforeRouteLeave(() => confirmarDescarte())
carregar()

const visiveis = computed(() => {
  const q = busca.value.trim().toLowerCase()
  return q ? linhas.value.filter((l) => `${l.animal.brinco} ${l.animal.nome ?? ''}`.toLowerCase().includes(q)) : linhas.value
})
const preenchidas = computed(() => linhas.value.filter((l) => paraNumero(l.valor) !== null))
const total = computed(() => preenchidas.value.reduce((s, l) => s + (paraNumero(l.valor) || 0), 0))
const invalida = (l) => {
  const n = paraNumero(l.valor)
  return n !== null && (Number.isNaN(n) || n < 0 || n > 999.99)
}

async function proxima(l) {
  const i = visiveis.value.indexOf(l)
  const seguinte = visiveis.value[i + 1]
  await nextTick()
  if (seguinte) entradas.value[seguinte.animal.id]?.$el?.focus?.() ?? entradas.value[seguinte.animal.id]?.focus?.()
}

async function salvar() {
  if (linhas.value.some(invalida)) return toast.error('Corrija os valores destacados (0 a 999,99 L).')
  salvando.value = true
  errosLinha.value = {}
  const enviadas = alteradas.value
  try {
    const { data: r } = await http.put('/producoes-leite/turno', {
      data_pesagem: data.value,
      periodo: periodo.value,
      registros: enviadas.map((l) => ({ animal_id: l.animal.id, quantidade_litros: paraNumero(l.valor) })),
    })
    const turno = TURNOS.find((t) => t.value === periodo.value).label.toLowerCase()
    toast.success(`Ordenha da ${turno} de ${dataBr(data.value)} salva`, {
      description: `${r.meta.gravados} lançamento(s)${r.meta.removidos ? `, ${r.meta.removidos} removido(s)` : ''} · total do turno ${numero(r.meta.total_litros, 1)} L`,
    })
    enviadas.forEach((l) => (l.original = l.valor))
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
const desfazer = () => linhas.value.forEach((l) => (l.valor = l.original))
</script>

<template>
  <CabecalhoPagina titulo="Ordenha">
    <template #acoes>
      <Input v-model="data" type="date" :min="dataMinima" :max="hoje()" class="h-9 w-40" aria-label="Data da ordenha" />
      <ToggleGroup v-model="periodo" type="single" variant="outline" aria-label="Turno">
        <ToggleGroupItem v-for="t in TURNOS" :key="t.value" :value="t.value" class="gap-1.5 px-3 data-[state=on]:bg-primary data-[state=on]:text-primary-foreground data-[state=on]:hover:bg-primary/90">
          <component :is="t.icone" /> {{ t.label }}
        </ToggleGroupItem>
      </ToggleGroup>
    </template>
    <template #filtros>
      <div class="relative w-full sm:w-64">
        <Search class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input v-model="busca" placeholder="Buscar vaca" class="pl-8" />
      </div>
      <p class="ml-auto text-sm text-muted-foreground">
        <b class="text-foreground tabular-nums">{{ preenchidas.length }}</b> de {{ linhas.length }} vacas ·
        <b class="text-foreground tabular-nums">{{ numero(total, 1) }} L</b> no turno
      </p>
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erros" />

    <Card class="gap-0 overflow-hidden py-0" :class="{ 'opacity-50': carregando }">
      <ul class="divide-y">
        <li
          v-for="l in visiveis" :key="l.animal.id"
          class="grid grid-cols-[auto_1fr_auto] items-center gap-x-3 gap-y-1 px-4 py-2.5 sm:grid-cols-[auto_1fr_8rem_9rem_1.25rem]"
          :class="{ 'bg-destructive/5': l.animal.em_carencia }"
        >
          <AvatarAnimal :animal="l.animal" :tamanho="40" />
          <div class="min-w-0">
            <p class="truncate font-medium">{{ l.animal.nome || l.animal.brinco }}</p>
            <p class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
              <span class="font-mono">{{ l.animal.brinco }}</span>
              <Badge v-if="l.animal.em_carencia" variant="destructive" class="px-1.5 py-0" :title="l.animal.alerta?.mensagem">
                <TriangleAlert /> descartar leite
              </Badge>
            </p>
          </div>
          <p class="hidden text-right text-xs text-muted-foreground sm:block">
            <template v-if="l.ontem !== null">Ontem<br /><span class="tabular-nums">{{ numero(l.ontem, 1) }} L</span></template>
          </p>
          <div class="w-28 sm:w-auto">
            <div class="relative">
              <Input
                :ref="(el) => (entradas[l.animal.id] = el)"
                v-model="l.valor" inputmode="decimal" autocomplete="off"
                :placeholder="l.ontem !== null ? numero(l.ontem, 1) : '0,0'"
                class="pr-7 text-right tabular-nums" :aria-label="`Litros de ${l.animal.nome || l.animal.brinco}`"
                :aria-invalid="invalida(l) || !!errosLinha[l.animal.id]"
                @keydown.enter.prevent="proxima(l)"
              />
              <span class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-xs text-muted-foreground">L</span>
            </div>
            <p v-if="errosLinha[l.animal.id]" class="mt-1 text-xs text-destructive">{{ errosLinha[l.animal.id] }}</p>
          </div>
          <span class="hidden sm:block" :title="l.valor !== l.original ? 'Não salvo' : (l.original ? 'Salvo' : '')">
            <CircleDot v-if="l.valor.trim() !== l.original.trim()" class="size-4 text-amber-500" />
            <Check v-else-if="l.original" class="size-4 text-emerald-600 dark:text-emerald-400" />
          </span>
        </li>
        <li v-if="!visiveis.length && !carregando" class="px-4 py-10 text-center text-sm text-muted-foreground">
          {{ linhas.length ? 'Nenhuma vaca encontrada na busca.' : 'Nenhuma vaca em lactação.' }}
        </li>
      </ul>
    </Card>
    <p class="text-xs text-muted-foreground">Dica: digite o valor e tecle <kbd class="rounded border px-1">Enter</kbd> para ir à próxima vaca. Apague um campo para remover o lançamento.</p>
  </main>

  <!-- barra de salvar: aparece quando há alterações -->
  <div v-if="alteradas.length" class="sticky bottom-0 z-30 border-t bg-background/95 backdrop-blur">
    <div class="flex items-center justify-end gap-3 px-4 py-3 md:px-6">
      <span class="mr-auto text-sm text-muted-foreground">{{ alteradas.length }} alteração(ões) não salva(s)</span>
      <Button variant="ghost" @click="desfazer"><Undo2 /> Desfazer</Button>
      <Button :disabled="salvando" @click="salvar"><Check /> {{ salvando ? 'Salvando…' : 'Salvar ordenha' }}</Button>
    </div>
  </div>
</template>
