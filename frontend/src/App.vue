<script setup>
import { useRoute } from 'vue-router'
import { useColorMode } from '@vueuse/core'
import { Toaster } from '@/components/ui/sonner'
import { TooltipProvider } from '@/components/ui/tooltip'
import 'vue-sonner/style.css'
import AvisoSuporte from './components/layout/AvisoSuporte.vue'
import BarraLateral from './components/layout/BarraLateral.vue'

const route = useRoute()
useColorMode() // aplica o tema salvo (ou o do sistema) em todas as telas, inclusive no login
</script>

<template>
  <TooltipProvider :delay-duration="0">
    <div class="flex min-h-screen bg-background">
      <BarraLateral v-if="!route.meta.publica && !route.meta.semFazenda" />
      <div class="min-w-0 flex-1">
        <AvisoSuporte v-if="!route.meta.publica && !route.meta.semFazenda" />
        <RouterView />
      </div>
      <Toaster rich-colors position="top-right" />
    </div>
  </TooltipProvider>
</template>
