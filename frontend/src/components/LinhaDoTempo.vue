<script setup>
import { Baby, HeartPulse, Milk, Scale, Syringe } from '@lucide/vue'
import { dataBr } from '../formatos'

/** RN01 — itens de GET /api/animais/{id}/timeline, do mais recente para o mais antigo. */
defineProps({ itens: { type: Array, default: () => [] } })

const ESTILO = {
  NASCIMENTO: { icone: Baby, cor: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300' },
  PESAGEM: { icone: Scale, cor: 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300' },
  PRODUCAO_LEITE: { icone: Milk, cor: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300' },
  EVENTO_REPRODUTIVO: { icone: HeartPulse, cor: 'bg-pink-100 text-pink-700 dark:bg-pink-950 dark:text-pink-300' },
  VACINA: { icone: Syringe, cor: 'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300' },
}
</script>

<template>
  <p v-if="!itens.length" class="text-sm text-muted-foreground">Nenhum registro ainda.</p>
  <ol v-else class="relative ml-4 border-l">
    <li v-for="(item, i) in itens" :key="i" class="mb-5 ml-6">
      <span class="absolute -left-4 flex size-8 items-center justify-center rounded-full ring-4 ring-card" :class="ESTILO[item.tipo]?.cor">
        <component :is="ESTILO[item.tipo]?.icone" class="size-4" />
      </span>
      <time class="text-xs text-muted-foreground">{{ dataBr(item.data) }}</time>
      <h3 class="text-sm font-medium">{{ item.titulo }}</h3>
      <p v-if="item.descricao" class="text-sm text-muted-foreground">{{ item.descricao }}</p>
    </li>
  </ol>
</template>
