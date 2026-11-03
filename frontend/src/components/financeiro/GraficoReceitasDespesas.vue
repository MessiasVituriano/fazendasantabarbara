<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { dataBr, mesCurto, moeda, moedaCompacta } from '../../formatos'

/**
 * Colunas agrupadas por período (A × B, ex.: receitas × despesas) com linha opcional (ex.: saldo),
 * tudo no mesmo eixo em R$ (uma única escala). Cores na ordem fixa da paleta: --chart-1, --chart-2, --chart-3.
 * Períodos "AAAA-MM" viram "nov/26"; "AAAA-MM-DD" viram "03/11".
 * Hover/foco mostra o tooltip; ←/→ percorrem os períodos pelo teclado.
 */
const props = defineProps({
  serie: { type: Array, required: true },
  chavePeriodo: { type: String, default: 'mes' },
  chaveA: { type: String, default: 'receitas' },
  rotuloA: { type: String, default: 'Receitas' },
  chaveB: { type: String, default: 'despesas' },
  rotuloB: { type: String, default: 'Despesas' },
  linha: { type: String, default: null },
  rotuloLinha: { type: String, default: 'Saldo' },
  descricao: { type: String, default: 'Receitas e despesas por período' },
})

const ALTURA = 260
const M = { topo: 12, direita: 8, base: 26, esquerda: 64 }
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
const val = (d, k) => Number(d?.[k]) || 0

/** Escala "redonda" (1, 2, 2.5, 5 × 10^n) que sempre inclui o zero; aceita saldo negativo. */
const escala = computed(() => {
  const valores = props.serie.flatMap((d) => [val(d, props.chaveA), val(d, props.chaveB), ...(props.linha ? [val(d, props.linha)] : [])])
  const max = Math.max(0, ...valores)
  const min = Math.min(0, ...valores)
  const amplitude = Math.max(max - min, 1)
  const bruto = amplitude / 4
  const pot = 10 ** Math.floor(Math.log10(bruto))
  const passo = [1, 2, 2.5, 5, 10].map((m) => m * pot).find((p) => p >= bruto)
  const teto = Math.ceil(max / passo) * passo || passo
  const piso = Math.floor(min / passo) * passo
  const marcas = []
  for (let v = piso; v <= teto + passo / 2; v += passo) marcas.push(Math.round(v * 100) / 100)
  return { teto, piso, marcas }
})
const y = (v) => M.topo + areaA - ((v - escala.value.piso) / (escala.value.teto - escala.value.piso)) * areaA
const zero = computed(() => y(0))

const faixa = computed(() => areaL.value / Math.max(props.serie.length, 1))
const barra = computed(() => Math.max(2, Math.min(18, (faixa.value * 0.7 - 2) / 2)))
const GAP = 2

function caminhoBarra(x, v, w) {
  const topo = y(Math.max(v, 0))
  const base = y(Math.min(v, 0))
  const h = base - topo
  if (h <= 0.5) return ''
  const r = Math.min(4, w / 2, h)
  // Ponta arredondada só na extremidade de dado (longe do zero).
  if (v >= 0) return `M${x},${base} V${topo + r} Q${x},${topo} ${x + r},${topo} H${x + w - r} Q${x + w},${topo} ${x + w},${topo + r} V${base} Z`
  return `M${x},${topo} V${base - r} Q${x},${base} ${x + r},${base} H${x + w - r} Q${x + w},${base} ${x + w},${base - r} V${topo} Z`
}

const colunas = computed(() => props.serie.map((d, i) => {
  const x0 = M.esquerda + i * faixa.value
  const xa = x0 + (faixa.value - (barra.value * 2 + GAP)) / 2
  return { d, i, x0, centro: x0 + faixa.value / 2, a: caminhoBarra(xa, val(d, props.chaveA), barra.value), b: caminhoBarra(xa + barra.value + GAP, val(d, props.chaveB), barra.value) }
}))
const pontosLinha = computed(() => (props.linha ? colunas.value.map((c) => `${c.centro},${y(val(c.d, props.linha))}`).join(' ') : ''))

const rotuloPeriodo = (p) => (String(p).length > 7 ? dataBr(p).slice(0, 5) : mesCurto(p))
const rotulosX = computed(() => {
  const cada = Math.max(1, Math.ceil(52 / faixa.value))
  return colunas.value.filter((c) => (props.serie.length - 1 - c.i) % cada === 0)
})

const ativo = ref(null)
const tooltipX = computed(() => (ativo.value === null ? 0 : Math.min(Math.max(colunas.value[ativo.value].centro, 100), largura.value - 100)))
function teclado(e) {
  const n = props.serie.length
  if (e.key === 'ArrowRight') ativo.value = ativo.value === null ? n - 1 : Math.min(n - 1, ativo.value + 1)
  else if (e.key === 'ArrowLeft') ativo.value = ativo.value === null ? n - 1 : Math.max(0, ativo.value - 1)
  else if (e.key === 'Escape') ativo.value = null
  else return
  e.preventDefault()
}
const series = computed(() => [
  [props.chaveA, props.rotuloA, 'var(--chart-1)'],
  [props.chaveB, props.rotuloB, 'var(--chart-2)'],
  ...(props.linha ? [[props.linha, props.rotuloLinha, 'var(--chart-3)']] : []),
])
</script>

<template>
  <div>
    <div class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
      <span v-for="s in series" :key="s[0]" class="flex items-center gap-1.5">
        <span v-if="s[0] === linha" class="h-0.5 w-3 rounded" :style="{ background: s[2] }" />
        <span v-else class="size-2.5 rounded-[2px]" :style="{ background: s[2] }" />
        {{ s[1] }}
      </span>
    </div>
    <div ref="caixa" class="relative" @pointerleave="ativo = null">
      <svg
        :width="largura" :height="ALTURA" class="block rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        role="img" tabindex="0" :aria-label="`${descricao}, ${serie.length} períodos. Use as setas para percorrer.`"
        @keydown="teclado" @blur="ativo = null"
      >
        <g>
          <template v-for="m in escala.marcas" :key="m">
            <line :x1="M.esquerda" :x2="largura - M.direita" :y1="y(m)" :y2="y(m)" :stroke="m === 0 ? 'var(--chart-eixo)' : 'var(--chart-grade)'" stroke-width="1" />
            <text :x="M.esquerda - 8" :y="y(m)" text-anchor="end" dominant-baseline="middle" class="fill-muted-foreground text-[11px] tabular-nums">{{ moedaCompacta(m) }}</text>
          </template>
        </g>

        <g v-for="c in colunas" :key="c.d[chavePeriodo]" :opacity="ativo === null || ativo === c.i ? 1 : 0.45">
          <path :d="c.a" fill="var(--chart-1)" />
          <path :d="c.b" fill="var(--chart-2)" />
        </g>

        <template v-if="linha">
          <polyline :points="pontosLinha" fill="none" stroke="var(--chart-3)" stroke-width="2" stroke-linejoin="round" />
          <circle v-for="c in colunas" v-show="ativo === c.i" :key="`p-${c.i}`" :cx="c.centro" :cy="y(val(c.d, linha))" r="4" fill="var(--chart-3)" stroke="var(--card)" stroke-width="2" />
        </template>

        <line v-if="ativo !== null" :x1="colunas[ativo].centro" :x2="colunas[ativo].centro" :y1="M.topo" :y2="M.topo + areaA" stroke="var(--chart-eixo)" stroke-dasharray="3 3" />
        <rect v-for="c in colunas" :key="`h-${c.i}`" :x="c.x0" :y="M.topo" :width="faixa" :height="areaA" fill="transparent" @pointerenter="ativo = c.i" @pointermove="ativo = c.i" />

        <text
          v-for="c in rotulosX" :key="`x-${c.i}`"
          :x="Math.min(Math.max(c.centro, M.esquerda + 16), largura - M.direita - 16)"
          :y="ALTURA - 8" text-anchor="middle" class="fill-muted-foreground text-[11px] tabular-nums"
        >{{ rotuloPeriodo(c.d[chavePeriodo]) }}</text>
        <line :x1="M.esquerda" :x2="largura - M.direita" :y1="zero" :y2="zero" stroke="var(--chart-eixo)" />
      </svg>

      <div
        v-if="ativo !== null"
        class="pointer-events-none absolute top-0 z-10 grid w-52 -translate-x-1/2 gap-1.5 rounded-lg border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow-xl"
        :style="{ left: `${tooltipX}px` }" role="status"
      >
        <p class="font-medium">{{ rotuloPeriodo(serie[ativo][chavePeriodo]) }}</p>
        <div v-for="s in series" :key="s[0]" class="flex items-center gap-2">
          <span class="h-2.5 w-1 shrink-0 rounded-[2px]" :style="{ background: s[2] }" />
          <span class="text-muted-foreground">{{ s[1] }}</span>
          <span class="ml-auto font-mono font-medium tabular-nums">{{ moeda(val(serie[ativo], s[0])) }}</span>
        </div>
        <div class="flex items-center gap-2 border-t pt-1">
          <span class="text-muted-foreground">Diferença</span>
          <span class="ml-auto font-mono font-semibold tabular-nums">{{ moeda(val(serie[ativo], chaveA) - val(serie[ativo], chaveB)) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
