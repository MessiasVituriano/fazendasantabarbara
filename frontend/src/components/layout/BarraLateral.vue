<script setup>
import { useLocalStorage } from '@vueuse/core'
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet'
import { useMenu } from '@/composables/useMenu'
import BotoesRodape from './BotoesRodape.vue'
import Marca from './Marca.vue'
import MenuNavegacao from './MenuNavegacao.vue'

/** Menu lateral: fixo e recolhível no desktop, gaveta no celular (tema/usuário ficam no cabeçalho). */
const recolhido = useLocalStorage('menu.recolhido', false)
const { aberto } = useMenu()
</script>

<template>
  <aside
    class="sticky top-0 hidden h-screen shrink-0 flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-[width] duration-200 md:flex"
    :class="recolhido ? 'w-16' : 'w-60'"
  >
    <div class="flex h-14 items-center px-3"><Marca :compacta="recolhido" /></div>
    <div class="flex-1 overflow-y-auto px-2 py-2"><MenuNavegacao :recolhido="recolhido" /></div>
    <div class="border-t border-sidebar-border p-2">
      <BotoesRodape :recolhido="recolhido" @recolher="recolhido = !recolhido" />
    </div>
  </aside>

  <Sheet v-model:open="aberto">
    <SheetContent side="left" class="w-72 bg-sidebar p-0">
      <SheetTitle class="sr-only">Menu</SheetTitle>
      <SheetDescription class="sr-only">Navegação do sistema</SheetDescription>
      <div class="flex h-full flex-col">
        <div class="flex h-14 items-center px-4"><Marca /></div>
        <div class="flex-1 px-2 py-2"><MenuNavegacao @navegou="aberto = false" /></div>
      </div>
    </SheetContent>
  </Sheet>
</template>
