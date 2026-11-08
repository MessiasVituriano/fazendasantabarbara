<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { dataBr, numero } from '../formatos'

/**
 * Peso ao longo do tempo: pesagens reais (pontos), reta de tendência da janela recente,
 * projeção tracejada com a faixa de incerteza e linha horizontal do peso alvo.
 * Uma única série de dados (--chart-1); alvo e grade em tinta neutra. Pesagens suspeitas
 * aparecem vazadas e ficam fora da tendência. Hover/foco: tooltip da pesagem mais próxima; ←/→ percorrem.
 */
const props = defineProps({
  pontos: { type: Array, required: true },          // [{ data, peso, suspeita?, detalhe? }]
  reta: { type: Object, default: null },             // { inicio: {data, peso}, fim: {data, peso} }
  projecao: { type: Array, default: () => [] },      // [{ data, peso, margem? }] a partir de hoje
  alvo: { type: Object, default: null },             // { peso, data?, rotulo }
  rotulo: { type: String, default: 'Peso' },
})

const ALTURA = 260
const M = { topo: 16, direita: 12, base: 26, esquerda: 48 }
const caixa = ref(null)
const largura = ref(640)
let observador
onMounted(() => {
  observador = new ResizeObserver(([e]) => (largura.value = Math.max(280, e.contentRect.width)))
  observador.observe(caixa.value)
})
onBeforeUnmount(() => observador?.disconnect())

const dia = (iso) => Date.parse(`${iso.slice(0, 10)}T12:00:00Z`) / 86400000
const areaL = computed(() => largura.value - M.esquerda - M.direita)
const areaA = ALTURA - M.topo - M.base

const dominioX = computed(() => {
  const dias = [...props.pontos.map((p) => p.data), ...props.projecao.map((p) => p.data), props.alvo?.data].filter(Boolean).map(dia)
  const min = Math.min(...dias)
  const max = Math.max(...dias)
  return { min, max: max > min ? max : min + 1 }
})
const x = (iso) => M.esquerda + ((dia(iso) - dominioX.value.min) / (dominioX.value.max - dominioX.value.min)) * areaL.value

/** Escala com passo "redondo" e alguma folga acima e abaixo. */
const escala = computed(() => {
  const vals = [
    ...props.pontos.filter((p) => !p.suspeita).map((p) => p.peso),
    ...props.projecao.flatMap((p) => [p.peso + (p.margem ?? 0), p.peso - (p.margem ?? 0)]),
    props.alvo?.peso,
  ].filter((v) => v != null)
  let min = Math.min(...vals)
  let max = Math.max(...vals)
  if (max - min < 10) { min -= 5; max += 5 }
  const bruto = (max - min) / 4
  const pot = 10 ** Math.floor(Math.log10(bruto))
  const passo = [1, 2, 2.5, 5, 10].map((m) => m * pot).find((p) => p >= bruto)
  const base = Math.floor(min / passo) * passo
  const teto = Math.ceil(max / passo) * passo
  return { base, teto, marcas: Array.from({ length: Math.round((teto - base) / passo) + 1 }, (_, i) => base + i * passo) }
})
const y = (v) => M.topo + areaA - ((v - escala.value.base) / (escala.value.teto - escala.value.base)) * areaA
// Suspeitas muito fora da escala ficam presas na borda (o tooltip mostra o valor real).
const yPreso = (v) => Math.min(Math.max(y(v), M.topo), M.topo + areaA)

const linhaReal = computed(() => props.pontos.filter((p) => !p.suspeita).map((p, i) => `${i ? 'L' : 'M'}${x(p.data)},${y(p.peso)}`).join(' '))
const linhaProjecao = computed(() => props.projecao.map((p, i) => `${i ? 'L' : 'M'}${x(p.data)},${y(p.peso)}`).join(' '))
const faixa = computed(() => {
  const com = props.projecao.filter((p) => p.margem != null)
  if (com.length < 2) return ''
  const topo = com.map((p, i) => `${i ? 'L' : 'M'}${x(p.data)},${y(p.peso + p.margem)}`).join(' ')
  const baixo = [...com].reverse().map((p) => `L${x(p.data)},${y(p.peso - p.margem)}`).join(' ')
  return `${topo} ${baixo} Z`
})

const rotulosX = computed(() => {
  const n = Math.max(2, Math.floor(areaL.value / 90))
  const { min, max } = dominioX.value
  return Array.from({ length: n + 1 }, (_, i) => {
    const d = new Date((min + ((max - min) * i) / n) * 86400000)
    return d.toISOString().slice(0, 10)
  })
})

// Tooltip: pesagens e pontos da projeção, em ordem de data.
const alvosHover = computed(() => [
  ...props.pontos.map((p) => ({ ...p, tipo: 'real' })),
  ...props.projecao.slice(1).map((p) => ({ ...p, tipo: 'projecao' })),
].sort((a, b) => dia(a.data) - dia(b.data)))
const ativo = ref(null)
function mover(e) {
  const r = e.currentTarget.getBoundingClientRect()
  const px = e.clientX - r.left
  let melhor = null
  let dist = Infinity
  alvosHover.value.forEach((p, i) => { const d = Math.abs(x(p.data) - px); if (d < dist) { dist = d; melhor = i } })
  ativo.value = melhor
}
function teclado(e) {
  const n = alvosHover.value.length
  if (e.key === 'ArrowRight') ativo.value = ativo.value === null ? 0 : Math.min(n - 1, ativo.value + 1)
  else if (e.key === 'ArrowLeft') ativo.value = ativo.value === null ? n - 1 : Math.max(0, ativo.value - 1)
  else if (e.key === 'Escape') ativo.value = null
  else return
  e.preventDefault()
}
const pontoAtivo = computed(() => (ativo.value === null ? null : alvosHover.value[ativo.value]))
const tooltipX = computed(() => (pontoAtivo.value ? Math.min(Math.max(x(pontoAtivo.value.data), 96), largura.value - 96) : 0))
</script>

<template>
  <div class="space-y-2">
    <div ref="caixa" class="relative" @pointerleave="ativo = null">
      <svg
        :width="largura" :height="ALTURA" class="block rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        role="img" tabindex="0" :aria-label="`${rotulo} ao longo do tempo, ${pontos.length} pesagens. Use as setas para percorrer.`"
        @keydown="teclado" @blur="ativo = null"
      >
        <g>
          <template v-for="m in escala.marcas" :key="m">
            <line :x1="M.esquerda" :x2="largura - M.direita" :y1="y(m)" :y2="y(m)" stroke="var(--chart-grade)" stroke-width="1" />
            <text :x="M.esquerda - 8" :y="y(m)" text-anchor="end" dominant-baseline="middle" class="fill-muted-foreground text-[11px] tabular-nums">{{ numero(m, 0) }}</text>
          </template>
          <text x="4" :y="M.topo - 4" class="fill-muted-foreground text-[10px]">kg</text>
          <text v-for="d in rotulosX" :key="d" :x="Math.min(Math.max(x(d), M.esquerda + 18), largura - M.direita - 18)" :y="ALTURA - 8"
                text-anchor="middle" class="fill-muted-foreground text-[11px] tabular-nums">{{ dataBr(d).slice(0, 5) }}</text>
        </g>

        <!-- peso alvo -->
        <g v-if="alvo">
          <line :x1="M.esquerda" :x2="largura - M.direita" :y1="y(alvo.peso)" :y2="y(alvo.peso)" stroke="var(--muted-foreground)" stroke-width="1.5" stroke-dasharray="2 4" />
          <text :x="M.esquerda + 6" :y="y(alvo.peso) - 6" class="fill-foreground text-[11px] font-medium">{{ alvo.rotulo }} · {{ numero(alvo.peso, 0) }} kg</text>
          <line v-if="alvo.data" :x1="x(alvo.data)" :x2="x(alvo.data)" :y1="M.topo" :y2="M.topo + areaA" stroke="var(--chart-eixo)" stroke-width="1" stroke-dasharray="2 4" />
        </g>

        <!-- projeção: faixa de incerteza + linha tracejada -->
        <path v-if="faixa" :d="faixa" fill="var(--chart-1)" opacity="0.12" />
        <path v-if="linhaProjecao" :d="linhaProjecao" fill="none" stroke="var(--chart-1)" stroke-width="2" stroke-dasharray="6 5" stroke-linecap="round" />

        <!-- tendência da janela recente -->
        <line v-if="reta" :x1="x(reta.inicio.data)" :y1="y(reta.inicio.peso)" :x2="x(reta.fim.data)" :y2="y(reta.fim.peso)"
              stroke="var(--chart-1)" stroke-width="1.5" opacity="0.45" />

        <!-- pesagens -->
        <path :d="linhaReal" fill="none" stroke="var(--chart-1)" stroke-width="2" stroke-linejoin="round" />
        <template v-for="p in pontos" :key="p.data">
          <circle v-if="p.suspeita" :cx="x(p.data)" :cy="yPreso(p.peso)" r="5" fill="var(--card)" stroke="var(--destructive)" stroke-width="2" />
          <circle v-else :cx="x(p.data)" :cy="y(p.peso)" r="4" fill="var(--chart-1)" stroke="var(--card)" stroke-width="2" />
        </template>

        <!-- crosshair -->
        <line v-if="pontoAtivo" :x1="x(pontoAtivo.data)" :x2="x(pontoAtivo.data)" :y1="M.topo" :y2="M.topo + areaA" stroke="var(--chart-eixo)" stroke-width="1" />
        <rect :x="M.esquerda" :y="M.topo" :width="areaL" :height="areaA" fill="transparent" @pointermove="mover" @pointerenter="mover" />
      </svg>

      <div
        v-if="pontoAtivo"
        class="pointer-events-none absolute top-0 z-10 grid w-48 -translate-x-1/2 gap-1 rounded-lg border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow-xl"
        :style="{ left: `${tooltipX}px` }" role="status"
      >
        <p class="font-medium">{{ dataBr(pontoAtivo.data) }}</p>
        <div class="flex items-center gap-2">
          <span class="h-2.5 w-1 shrink-0 rounded-[2px]" :class="pontoAtivo.suspeita ? 'bg-destructive' : ''" :style="pontoAtivo.suspeita ? {} : { background: 'var(--chart-1)' }" />
          <span class="text-muted-foreground">{{ pontoAtivo.tipo === 'projecao' ? 'Projeção' : rotulo }}</span>
          <span class="ml-auto font-mono font-semibold tabular-nums">{{ numero(pontoAtivo.peso, 1) }} kg</span>
        </div>
        <p v-if="pontoAtivo.margem" class="text-muted-foreground">entre {{ numero(pontoAtivo.peso - pontoAtivo.margem, 0) }} e {{ numero(pontoAtivo.peso + pontoAtivo.margem, 0) }} kg</p>
        <p v-if="pontoAtivo.detalhe" class="text-muted-foreground">{{ pontoAtivo.detalhe }}</p>
        <p v-if="pontoAtivo.suspeita" class="font-medium text-destructive">Pesagem suspeita: fora da tendência</p>
      </div>
    </div>

    <!-- legenda -->
    <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
      <li class="flex items-center gap-1.5"><svg width="18" height="8"><line x1="0" y1="4" x2="18" y2="4" stroke="var(--chart-1)" stroke-width="2" /><circle cx="9" cy="4" r="3" fill="var(--chart-1)" /></svg>{{ rotulo }}</li>
      <li v-if="projecao.length" class="flex items-center gap-1.5"><svg width="18" height="8"><line x1="0" y1="4" x2="18" y2="4" stroke="var(--chart-1)" stroke-width="2" stroke-dasharray="4 3" /></svg>{{ faixa ? 'Projeção (faixa = incerteza)' : 'Projeção' }}</li>
      <li v-if="alvo" class="flex items-center gap-1.5"><svg width="18" height="8"><line x1="0" y1="4" x2="18" y2="4" stroke="var(--muted-foreground)" stroke-width="1.5" stroke-dasharray="2 3" /></svg>{{ alvo.rotulo }}</li>
      <li v-if="pontos.some((p) => p.suspeita)" class="flex items-center gap-1.5"><svg width="10" height="10"><circle cx="5" cy="5" r="3.5" fill="none" stroke="var(--destructive)" stroke-width="2" /></svg>Suspeita (fora da conta)</li>
    </ul>
  </div>
</template>
