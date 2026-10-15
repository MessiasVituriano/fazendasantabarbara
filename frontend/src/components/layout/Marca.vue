<script setup>
import { Wheat } from '@lucide/vue'

defineProps({ compacta: { type: Boolean, default: false } })
</script>

<template>
  <div class="flex items-center gap-2">
    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
      <Wheat class="size-5" />
    </span>
    <div v-if="!compacta" class="min-w-0 leading-tight">
      <p class="truncate text-sm font-semibold">Fazenda Santa Bárbara</p>
      <p class="truncate text-xs text-muted-foreground">Gestão Agropecuária</p>
    </div>
  </div>
</template>
