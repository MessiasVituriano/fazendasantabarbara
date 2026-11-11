<script setup>
import { Wheat } from '@lucide/vue'
import { useSistema } from '@/composables/useSistema'

/** Moldura das telas sem login (convite, nova senha): marca do produto e o conteúdo centralizado. */
const { produto } = useSistema()
</script>

<template>
  <main class="flex min-h-screen w-full items-center justify-center bg-muted/40 p-4">
    <div class="w-full max-w-md space-y-6">
      <div class="flex flex-col items-center gap-3 text-center">
        <span class="flex size-12 items-center justify-center rounded-xl bg-primary text-primary-foreground"><Wheat class="size-7" /></span>
        <h1 class="text-xl font-semibold">{{ produto }}</h1>
      </div>
      <slot />
    </div>
  </main>
</template>
