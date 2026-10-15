<script setup>
import { computed, ref } from 'vue'
import { numero } from '../formatos'

/**
 * Participação de cada vaca no total de leite do período (rosca).
 * Pizza só lê bem participação, não comparação fina: no máximo 6 fatias
 * (5 maiores + "Outras"). A comparação precisa fica no ranking em barras.
 * Cores pelas variáveis de tema (--chart-1…5), validadas em ordem fixa; "Outras" em cinza neutro.
 */
const props = defineProps({ ranking: { type: Array, required: true } })

const CORES = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)']
const COR_OUTRAS = 'var(--chart-outros)'
const MAX_FATIAS = 6

const fatias = computed(() => {
  const ordenadas = [...props.ranking].sort((a, b) => b.total_litros - a.total_litros)
  const total = ordenadas.reduce((s, v) => s + v.total_litros, 0)
  if (!total) return []
  // Até 6 vacas: todas aparecem. Mais que isso: 5 maiores + "Outras".
  const principais = ordenadas.length <= MAX_FATIAS ? ordenadas : ordenadas.slice(0, MAX_FATIAS - 1)
  const resto = ordenadas.slice(principais.length)
  const itens = principais.map((v, i) => ({
    chave: v.animal_id, rotulo: v.nome || v.brinco, detalhe: v.brinco, litros: v.total_litros, cor: CORES[i] ?? COR_OUTRAS,
  }))
  if (resto.length) {
    itens.push({
      chave: 'outras', rotulo: 'Outras', detalhe: `${resto.length} vaca(s)`,
      litros: resto.reduce((s, v) => s + v.total_litros, 0), cor: COR_OUTRAS,
    })
  }
  let acumulado = 0
  return itens.map((f) => {
    const inicio = acumulado / total
    acumulado += f.litros
    return { ...f, pct: (f.litros / total) * 100, inicio, fim: acumulado / total }
  })
})
const total = computed(() => fatias.value.reduce((s, f) => s + f.litros, 0))

// Geometria da rosca (viewBox 220×220), começando às 12h, sentido horário.
const C = 110
const R = 104
const r = 66
const ponto = (frac, raio) => {
  const ang = frac * 2 * Math.PI - Math.PI / 2
  return [C + raio * Math.cos(ang), C + raio * Math.sin(ang)]
}
function arco(f, extra = 0) {
  if (f.fim - f.inicio >= 0.9999) {
    // fatia única: anel completo
    return `M${C},${C - R - extra} A${R + extra},${R + extra} 0 1 1 ${C - 0.01},${C - R - extra} Z M${C},${C - r} A${r},${r} 0 1 0 ${C + 0.01},${C - r} Z`
  }
  const grande = f.fim - f.inicio > 0.5 ? 1 : 0
  const [x1, y1] = ponto(f.inicio, R + extra)
  const [x2, y2] = ponto(f.fim, R + extra)
  const [x3, y3] = ponto(f.fim, r)
  const [x4, y4] = ponto(f.inicio, r)
  return `M${x1},${y1} A${R + extra},${R + extra} 0 ${grande} 1 ${x2},${y2} L${x3},${y3} A${r},${r} 0 ${grande} 0 ${x4},${y4} Z`
}

const ativa = ref(null)
const destaque = computed(() => fatias.value.find((f) => f.chave === ativa.value))
</script>

<template>
  <p v-if="!fatias.length" class="text-sm text-muted-foreground">Nenhuma ordenha registrada no período.</p>
  <!-- lado a lado só quando o próprio cartão é largo (container query), senão empilha -->
  <div v-else class="@container">
  <div class="flex flex-col items-center gap-5 @lg:flex-row">
    <svg viewBox="0 0 220 220" class="w-56 shrink-0" role="img"
         :aria-label="`Participação de cada vaca no total de ${numero(total, 0)} litros`"
         @pointerleave="ativa = null">
      <!-- 2px de respiro entre fatias, na cor da superfície -->
      <path
        v-for="f in fatias" :key="f.chave" :d="arco(f, ativa === f.chave ? 4 : 0)" :fill="f.cor"
        stroke="var(--card)" stroke-width="2" stroke-linejoin="round" fill-rule="evenodd"
        :opacity="ativa === null || ativa === f.chave ? 1 : 0.4"
        class="cursor-pointer transition-opacity"
        @pointerenter="ativa = f.chave"
      />
      <!-- centro: total, ou a fatia em foco (faz o papel do tooltip) -->
      <text :x="C" :y="C - 8" text-anchor="middle" class="fill-foreground text-[22px] font-semibold">
        {{ destaque ? `${numero(destaque.pct, 1)}%` : `${numero(total, 0)} L` }}
      </text>
      <text :x="C" :y="C + 14" text-anchor="middle" class="fill-muted-foreground text-[11px]">
        {{ destaque ? destaque.rotulo : 'total no período' }}
      </text>
      <text v-if="destaque" :x="C" :y="C + 30" text-anchor="middle" class="fill-muted-foreground text-[11px]">
        {{ numero(destaque.litros, 0) }} L
      </text>
    </svg>

    <!-- legenda com valores: identidade nunca depende só da cor -->
    <ul class="w-full space-y-0.5 text-sm" role="list">
      <li v-for="f in fatias" :key="f.chave">
        <button
          class="grid w-full grid-cols-[auto_1fr_auto_auto] items-center gap-x-3 rounded px-2 py-1.5 text-left hover:bg-accent focus:bg-accent focus:outline-none"
          :class="{ 'bg-accent': ativa === f.chave }"
          @pointerenter="ativa = f.chave" @pointerleave="ativa = null" @focus="ativa = f.chave" @blur="ativa = null"
        >
          <span class="h-3 w-3 rounded-sm" :style="{ background: f.cor }" />
          <span class="min-w-0">
            <span class="block truncate font-medium">{{ f.rotulo }}</span>
            <span class="block text-xs text-muted-foreground" :class="{ 'font-mono': f.chave !== 'outras' }">{{ f.detalhe }}</span>
          </span>
          <span class="tabular-nums whitespace-nowrap text-muted-foreground">{{ numero(f.litros, 0) }} L</span>
          <span class="w-14 text-right font-semibold tabular-nums">{{ numero(f.pct, 1) }}%</span>
        </button>
      </li>
    </ul>
  </div>
  </div>
</template>
