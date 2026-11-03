<script setup>
import { moeda } from '../../formatos'

/** Valor com sinal e cor: receita em verde (+), despesa em vermelho (−). */
defineProps({ valor: { type: [Number, String], default: null }, tipo: { type: String, required: true } })
</script>

<template>
  <span class="tabular-nums whitespace-nowrap" :class="tipo === 'RECEITA' ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'">
    {{ tipo === 'RECEITA' ? '+' : '−' }} {{ moeda(valor) }}
  </span>
</template>
