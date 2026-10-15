<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { dataBr, numero } from '../formatos'

/**
 * Colunas empilhadas por dia: Manhã (base) + Tarde (topo), em litros.
 * Cores pelas variáveis de tema (--chart-1/2), validadas para claro e escuro.
 * Hover/foco em qualquer ponto da faixa do dia mostra o tooltip; ←/→ navegam pelo teclado.
 */
const props = defineProps({ serie: { type: Array, required: true } })

const ALTURA = 260
const M = { topo: 12, direita: 8, base: 26, esquerda: 44 }

const caixa = ref(null)
const largura = ref(640)
let observador
onMounted(() => {
  observador = new ResizeObserver(([e]) => (largura.value = Math.max(280, e.contentRect.width)))
  observador.observe(caixa.value)
})
onBeforeUnmount(() => observador?.disconnect())

const areaL = computed(() => largura.value - M.esquerda - M.direita)
const areaA = ALTURA - M.topo - M.base

/** Escala com teto "redondo" (1, 2, 2.5, 5 × 10^n) e 4–5 marcas. */
const escala = computed(() => {
  const max = Math.max(...props.serie.map((d) => d.total), 1)
  const bruto = max / 4
  const pot = 10 ** Math.floor(Math.log10(bruto))
  const passo = [1, 2, 2.5, 5, 10].map((m) => m * pot).find((p) => p >= bruto)
  const teto = Math.ceil(max / passo) * passo
  return { teto, marcas: Array.from({ length: Math.round(teto / passo) + 1 }, (_, i) => i * passo) }
})
const y = (v) => M.topo + areaA - (v / escala.value.teto) * areaA

const faixa = computed(() => areaL.value / props.serie.length)
const barra = computed(() => Math.max(2, Math.min(24, faixa.value * 0.68)))
const GAP = 2

const colunas = computed(() => props.serie.map((d, i) => {
  const x0 = M.esquerda + i * faixa.value
  const hManha = (d.manha / escala.value.teto) * areaA
  const hTarde = (d.tarde / escala.value.teto) * areaA
  const base = M.topo + areaA
  return {
    d, i, x0, xb: x0 + (faixa.value - barra.value) / 2,
    manha: { y: base - hManha, h: hManha },
    tarde: { y: base - hManha - hTarde, h: Math.max(0, hTarde - (hManha > 0 ? GAP : 0)) },
  }
}))

const rotulosX = computed(() => {
  const cada = Math.max(1, Math.ceil(56 / faixa.value))
  return colunas.value.filter((c) => (props.serie.length - 1 - c.i) % cada === 0)
})

function caminhoBarra(x, yTopo, w, h, arredondar) {
  if (h <= 0) return ''
  const r = arredondar ? Math.min(4, w / 2, h) : 0
  const yb = yTopo + h
  return `M${x},${yb} V${yTopo + r} Q${x},${yTopo} ${x + r},${yTopo} H${x + w - r} Q${x + w},${yTopo} ${x + w},${yTopo + r} V${yb} Z`
}

const ativo = ref(null)
const tooltipX = computed(() => {
  if (ativo.value === null) return 0
  const centro = colunas.value[ativo.value].x0 + faixa.value / 2
  return Math.min(Math.max(centro, 90), largura.value - 90)
})
function teclado(e) {
  const n = props.serie.length
  if (e.key === 'ArrowRight') ativo.value = ativo.value === null ? n - 1 : Math.min(n - 1, ativo.value + 1)
  else if (e.key === 'ArrowLeft') ativo.value = ativo.value === null ? n - 1 : Math.max(0, ativo.value - 1)
  else if (e.key === 'Escape') ativo.value = null
  else return
  e.preventDefault()
}
const compacto = (v) => (v >= 1000 ? `${numero(v / 1000, v % 1000 ? 1 : 0)} mil` : numero(v, 0))
</script>

<template>
  <div ref="caixa" class="relative" @pointerleave="ativo = null">
    <svg
      :width="largura" :height="ALTURA" class="block rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
      role="img" tabindex="0"
      :aria-label="`Produção diária de leite, ${serie.length} dias. Use as setas para percorrer os dias.`"
      @keydown="teclado" @blur="ativo = null"
    >
      <g>
        <template v-for="m in escala.marcas" :key="m">
          <line :x1="M.esquerda" :x2="largura - M.direita" :y1="y(m)" :y2="y(m)" :stroke="m === 0 ? 'var(--chart-eixo)' : 'var(--chart-grade)'" stroke-width="1" />
          <text :x="M.esquerda - 8" :y="y(m)" text-anchor="end" dominant-baseline="middle" class="fill-muted-foreground text-[11px] tabular-nums">
            {{ compacto(m) }}
          </text>
        </template>
        <text :x="4" :y="M.topo - 2" class="fill-muted-foreground text-[10px]">L</text>
      </g>

      <g v-for="c in colunas" :key="c.d.data" :opacity="ativo === null || ativo === c.i ? 1 : 0.45">
        <path :d="caminhoBarra(c.xb, c.manha.y, barra, c.manha.h, c.tarde.h <= 0)" fill="var(--chart-1)" />
        <path :d="caminhoBarra(c.xb, c.tarde.y, barra, c.tarde.h, true)" fill="var(--chart-2)" />
        <rect :x="c.x0" :y="M.topo" :width="faixa" :height="areaA" fill="transparent" @pointerenter="ativo = c.i" @pointermove="ativo = c.i" />
      </g>

      <text
        v-for="c in rotulosX" :key="`x-${c.d.data}`"
        :x="Math.min(Math.max(c.x0 + faixa / 2, M.esquerda + 16), largura - M.direita - 16)"
        :y="ALTURA - 8" text-anchor="middle" class="fill-muted-foreground text-[11px] tabular-nums"
      >{{ dataBr(c.d.data).slice(0, 5) }}</text>
    </svg>

    <!-- tooltip no padrão shadcn: valor em destaque, rótulo secundário, chave em traço -->
    <div
      v-if="ativo !== null"
      class="pointer-events-none absolute top-0 z-10 grid w-44 -translate-x-1/2 gap-1.5 rounded-lg border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow-xl"
      :style="{ left: `${tooltipX}px` }" role="status"
    >
      <p class="font-medium">{{ dataBr(serie[ativo].data) }}</p>
      <div class="grid gap-1">
        <div v-for="s in [['manha', 'Manhã', 'var(--chart-1)'], ['tarde', 'Tarde', 'var(--chart-2)']]" :key="s[0]" class="flex items-center gap-2">
          <span class="h-2.5 w-1 shrink-0 rounded-[2px]" :style="{ background: s[2] }" />
          <span class="text-muted-foreground">{{ s[1] }}</span>
          <span class="ml-auto font-mono font-medium tabular-nums">{{ numero(serie[ativo][s[0]], 1) }} L</span>
        </div>
        <div class="flex items-center gap-2 border-t pt-1">
          <span class="text-muted-foreground">Total</span>
          <span class="ml-auto font-mono font-semibold tabular-nums">{{ numero(serie[ativo].total, 1) }} L</span>
        </div>
        <p class="text-muted-foreground">{{ serie[ativo].vacas }} vaca(s) ordenhada(s)</p>
        <p v-if="serie[ativo].em_carencia > 0" class="font-medium text-destructive">⚠ {{ numero(serie[ativo].em_carencia, 1) }} L em carência</p>
      </div>
    </div>
  </div>
</template>
