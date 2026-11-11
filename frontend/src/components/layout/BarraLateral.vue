<script setup>
import { computed } from 'vue'
import { useLocalStorage, useMediaQuery } from '@vueuse/core'
import { Menu } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet'
import { useMenu } from '@/composables/useMenu'
import Marca from './Marca.vue'
import MenuNavegacao from './MenuNavegacao.vue'

/**
 * Menu lateral com o botão hambúrguer no topo: no desktop recolhe/expande (só ícones),
 * no tablet abre a gaveta com os nomes e no celular a gaveta abre pelo botão do cabeçalho.
 */
const preferenciaRecolhido = useLocalStorage('menu.recolhido', false)
const telaGrande = useMediaQuery('(min-width: 1024px)')
const recolhido = computed(() => !telaGrande.value || preferenciaRecolhido.value)
const { aberto } = useMenu()

function alternar() {
  if (telaGrande.value) preferenciaRecolhido.value = !preferenciaRecolhido.value
  else aberto.value = true
}
const rotulo = computed(() => {
  if (!telaGrande.value) return 'Abrir menu'
  return preferenciaRecolhido.value ? 'Expandir menu' : 'Recolher menu'
})
</script>

<template>
  <aside
    class="sticky top-0 hidden h-screen shrink-0 flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-[width] duration-200 md:flex"
    :class="recolhido ? 'w-16' : 'w-60'"
  >
    <div class="flex h-14 items-center gap-1 px-3" :class="{ 'justify-center': recolhido }">
      <Button variant="ghost" size="icon" class="shrink-0" :aria-label="rotulo" :title="rotulo" @click="alternar"><Menu /></Button>
      <Marca v-if="!recolhido" class="min-w-0" />
    </div>
    <div class="flex-1 overflow-y-auto px-2 py-2"><MenuNavegacao :recolhido="recolhido" /></div>
  </aside>

  <Sheet v-model:open="aberto">
    <SheetContent side="left" class="w-72 bg-sidebar p-0">
      <SheetTitle class="sr-only">Menu</SheetTitle>
      <SheetDescription class="sr-only">Navegação do sistema</SheetDescription>
      <div class="flex h-full flex-col">
        <div class="flex h-14 items-center px-4"><Marca /></div>
        <div class="min-h-0 flex-1 overflow-y-auto px-2 py-2"><MenuNavegacao @navegou="aberto = false" /></div>
      </div>
    </SheetContent>
  </Sheet>
</template>
