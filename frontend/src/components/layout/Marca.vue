<script setup>
import { Wheat } from '@lucide/vue'
import { useSessao } from '@/composables/useSessao'
import { useSistema } from '@/composables/useSistema'

defineProps({ compacta: { type: Boolean, default: false } })

/** Fazenda ativa e nome do produto, no topo do menu lateral. */
const { fazendaAtual } = useSessao()
const { produto } = useSistema()
</script>

<template>
  <div class="flex items-center gap-2">
    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
      <Wheat class="size-5" />
    </span>
    <div v-if="!compacta" class="min-w-0 leading-tight">
      <p class="truncate text-sm font-semibold">{{ fazendaAtual?.nome ?? produto }}</p>
      <p v-if="fazendaAtual" class="truncate text-xs text-muted-foreground">{{ produto }}</p>
    </div>
  </div>
</template>
