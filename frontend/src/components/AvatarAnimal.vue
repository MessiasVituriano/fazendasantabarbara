<script setup>
import { computed, ref, useId, watch } from 'vue'

/**
 * Avatar do animal: foto principal (se houver) ou silhueta de perfil em estilo plano, gerada a partir dos dados (sem foto).
 *  - pelagem pela aptidão: Leite = malhado preto e branco (tipo Holandês),
 *    Corte = branco-acinzentado (tipo Nelore), Mista = vermelho (tipo Gir/Girolando)
 *  - Corte/Mista: cupim e barbela, mais marcados nos machos
 *  - fêmeas em lactação/secas: úbere; machos adultos: chifres; bezerros: corpo menor
 *  - fundo pela categoria; inativos (vendido/morto/abatido) em tons de cinza
 */
const props = defineProps({
  animal: { type: Object, required: true },
  tamanho: { type: Number, default: 56 },
})

const uid = useId()

// Com foto principal, ela substitui a silhueta; se a imagem falhar ao carregar, volta para a silhueta.
const falhou = ref(false)
const fotoUrl = computed(() => (falhou.value ? null : props.animal.foto?.miniatura_url))
watch(() => props.animal.foto?.miniatura_url, () => (falhou.value = false))

const PELAGEM = {
  LEITE: { corpo: '#fafaf9', sombra: '#a8a29e', mancha: '#1c1917', cabeca: '#1c1917', orelha: '#292524', focinho: '#57534e' },
  CORTE: { corpo: '#e7e5e4', sombra: '#a8a29e', mancha: '#78716c', cabeca: '#d6d3d1', orelha: '#c4c0bc', focinho: '#44403c' },
  MISTA: { corpo: '#9a3412', sombra: '#6b230b', mancha: '#431407', cabeca: '#7c2d12', orelha: '#6b230b', focinho: '#2a0d04' },
}

const TRONCO = 'M19.5 23.6 C28 20.6 42 20.6 50 22 C54.2 23 55.2 28 54.2 33 C53.6 37 52 39.6 48 39.6 L24 39.6 C20 39.6 17.6 37 17.6 33 Z'
const CABECA = 'M22.4 23.4 C18.6 22.8 15 23.4 12.4 25 C10.6 26.4 8.6 29.6 7.2 32.4 C6.4 34.4 6.6 36.6 8.4 37 C10.2 37.4 12.4 36.6 13.8 35.2 C15.8 34.4 19 35.2 22 37.8 Z'
const FUNDO = {
  BEZERRO: '#fef9c3', NOVILHA: '#ecfccb', GARROTE: '#ffedd5', LACTACAO: '#dbeafe',
  SECA: '#f5f5f4', TOURO: '#fee2e2', BOI_GORDO: '#fde68a',
}

const pelo = computed(() => PELAGEM[props.animal.aptidao] ?? PELAGEM.MISTA)
const fundo = computed(() => FUNDO[props.animal.categoria] ?? '#f5f5f4')
const bezerro = computed(() => props.animal.categoria === 'BEZERRO')
const macho = computed(() => props.animal.sexo === 'M')
const zebuino = computed(() => props.animal.aptidao !== 'LEITE')
const chifres = computed(() => macho.value && !bezerro.value)
const cupim = computed(() => zebuino.value && !bezerro.value)
const ubere = computed(() => !macho.value && ['LACTACAO', 'SECA'].includes(props.animal.categoria))
const inativo = computed(() => props.animal.status_geral && props.animal.status_geral !== 'ATIVO')
// Bezerro: mesma silhueta, menor e apoiada no chão.
const escala = computed(() => (bezerro.value ? 'translate(7 9) scale(0.8)' : 'translate(-3.5 -5) scale(1.1)'))
</script>

<template>
  <img
    v-if="fotoUrl" :src="fotoUrl" :width="tamanho" :height="tamanho" loading="lazy"
    :alt="animal.nome || animal.brinco"
    class="shrink-0 rounded-full border bg-muted object-cover" :class="{ 'opacity-60 grayscale': inativo }"
    :style="{ width: `${tamanho}px`, height: `${tamanho}px` }"
    @error="falhou = true"
  />
  <svg
    v-else
    :width="tamanho" :height="tamanho" viewBox="0 0 64 64" role="img"
    :aria-label="`${animal.nome || animal.brinco}`"
    class="shrink-0" :class="{ 'opacity-60 grayscale': inativo }"
  >
    <defs>
      <clipPath :id="`fundo-${uid}`"><circle cx="32" cy="32" r="31" /></clipPath>
      <clipPath :id="`corpo-${uid}`"><path :d="TRONCO" /></clipPath>
    </defs>

    <g :clip-path="`url(#fundo-${uid})`">
      <circle cx="32" cy="32" r="31" :fill="fundo" />
      <rect x="0" y="49.5" width="64" height="15" fill="#000" opacity="0.05" />

      <g :transform="escala">
        <ellipse cx="35" cy="49.8" rx="20" ry="1.8" fill="#000" opacity="0.12" />
        <!-- pernas do lado de lá (sombreadas) -->
        <path d="M23.4 36 L27 36 L26.6 49 L24 49 Z" :fill="pelo.sombra" />
        <path d="M48 35 L51.8 35 L51.2 49 L48.6 49 Z" :fill="pelo.sombra" />
        <!-- cauda -->
        <path d="M53.4 25 C55.6 30 55.4 37 55 43" fill="none" :stroke="pelo.sombra" stroke-width="1.1" stroke-linecap="round" />
        <path d="M54.1 42 C56.2 43 56.2 46 54.9 47 C53.7 46 53.5 43.5 54.1 42 Z" :fill="pelo.mancha" />
        <!-- úbere -->
        <path v-if="ubere" d="M38.8 37.5 C38.8 44.4 48.2 44.4 48.2 37.5 Z" fill="#e9a1aa" />
        <!-- pernas do lado de cá -->
        <path d="M18.8 34 L23.4 34 L22.6 48.6 L19.6 48.6 Z" :fill="pelo.corpo" />
        <path d="M43.6 34 L48.4 34 L47.4 48.6 L44.4 48.6 Z" :fill="pelo.corpo" />
        <rect x="19.5" y="48" width="3.2" height="1.6" rx="0.4" fill="#292524" />
        <rect x="44.3" y="48" width="3.2" height="1.6" rx="0.4" fill="#292524" />
        <rect x="24" y="48" width="2.7" height="1.6" rx="0.4" fill="#292524" opacity="0.8" />
        <rect x="48.5" y="48" width="2.8" height="1.6" rx="0.4" fill="#292524" opacity="0.8" />
        <!-- tronco -->
        <path :d="TRONCO" :fill="pelo.corpo" />
        <!-- volume do flanco -->
        <ellipse cx="36" cy="37.5" rx="16" ry="2.6" fill="#000" opacity="0.07" :clip-path="`url(#corpo-${uid})`" />
        <!-- malhado (Leite) recortado no tronco -->
        <g v-if="animal.aptidao === 'LEITE'" :clip-path="`url(#corpo-${uid})`" :fill="pelo.mancha">
          <path d="M26 20 C33 21 36.5 26.5 33.5 31 C30.5 34.5 23.5 32.5 21.5 28 C20.5 24.5 22.5 20 26 20 Z" />
          <path d="M42 19.5 C49.5 20.5 52.5 25 50.5 29.5 C47.5 32.5 41 30.5 39.8 26.2 C39 23 40 19.5 42 19.5 Z" />
          <path d="M34.5 34.5 C39 33.5 42 36.8 41.2 41 L31.5 41 C30.8 37.8 31.8 35 34.5 34.5 Z" />
        </g>
        <!-- cupim (zebuínos) -->
        <path v-if="cupim" :d="macho ? 'M18.6 25.5 C18.8 17.4 26.4 16.6 29 23.4 Z' : 'M19.6 24.8 C20.6 20.4 25.2 20.2 27.4 23.6 Z'" :fill="pelo.cabeca" />
        <!-- pescoço e cabeça -->
        <path :d="CABECA" :fill="pelo.cabeca" />
        <!-- faixa branca na testa (Holandês) -->
        <path v-if="animal.aptidao === 'LEITE'" d="M12.4 25.6 C11.4 27.6 9.6 30.6 8.6 33.2 L10 33.6 C11 31 12.6 28.2 13.8 26 Z" fill="#f5f5f4" />
        <!-- barbela (zebuínos) -->
        <path v-if="zebuino && !bezerro" :d="macho ? 'M14 34.4 C15.6 40.4 19.6 41.6 22.4 37.2 Z' : 'M14.6 34.6 C16 38.6 19 39.6 21.8 37 Z'" :fill="pelo.cabeca" />
        <!-- focinho -->
        <path d="M6.6 33 C6.1 35.2 7 36.9 8.7 36.9 C9.8 36.9 10.7 36.5 11.3 36 C10.2 34.6 8.6 33.5 6.6 33 Z" :fill="pelo.focinho" />
        <!-- orelha -->
        <path d="M14 26.2 C16.2 25 19 25.4 20 26.8 C18.2 27.8 15.8 27.8 14 26.2 Z" :fill="pelo.orelha" />
        <!-- chifre -->
        <path v-if="chifres" d="M12.8 25.4 C12.9 22.8 14.2 21 16.2 20.3 C15.4 22 15 23.6 15.2 25.2 Z" fill="#e7dcc2" />
        <!-- olho -->
        <circle cx="11.6" cy="29.4" r="0.85" :fill="animal.aptidao === 'LEITE' ? '#f5f5f4' : '#1c1917'" />
      </g>
    </g>
    <circle cx="32" cy="32" r="31" fill="none" stroke="#d6d3d1" />
  </svg>
</template>
