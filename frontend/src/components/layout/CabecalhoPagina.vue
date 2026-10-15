<script setup>
import { Menu } from '@lucide/vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useMenu } from '@/composables/useMenu'
import MenuUsuario from './MenuUsuario.vue'

/**
 * Cabeçalho fixo de cada tela: título, contador, ações da página, tema/usuário e faixa de filtros.
 * No celular as ações da página descem para uma linha própria; tema/usuário ficam sempre no topo.
 */
defineProps({ titulo: { type: String, required: true }, contador: { type: [Number, String], default: null } })
const { aberto } = useMenu()
</script>

<template>
  <header class="sticky top-0 z-20 border-b bg-background/85 backdrop-blur">
    <div class="flex min-h-14 flex-wrap items-center gap-x-2 gap-y-2 px-4 py-2 md:gap-x-3 md:px-6">
      <Button variant="ghost" size="icon" class="-ml-2 md:hidden" aria-label="Abrir menu" @click="aberto = true"><Menu /></Button>
      <slot name="antes-titulo" />
      <h1 class="min-w-0 truncate text-base font-semibold">{{ titulo }}</h1>
      <Badge v-if="contador !== null" variant="secondary" class="shrink-0 tabular-nums">{{ contador }}</Badge>

      <!-- tema e usuário: sempre na primeira linha, à direita -->
      <div class="ml-auto md:order-last" :class="{ 'md:ml-0 md:border-l md:pl-2': $slots.acoes }"><MenuUsuario /></div>

      <!-- ações da página: linha própria no celular, à direita no desktop -->
      <div v-if="$slots.acoes" class="order-last flex w-full flex-wrap items-center gap-2 md:order-none md:ml-auto md:w-auto md:justify-end">
        <slot name="acoes" />
      </div>
    </div>
    <div v-if="$slots.filtros" class="flex flex-wrap items-center gap-2 border-t px-4 py-2.5 md:px-6">
      <slot name="filtros" />
    </div>
  </header>
</template>
