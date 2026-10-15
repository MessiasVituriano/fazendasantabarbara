<script setup>
import { PanelLeft } from '@lucide/vue'
import { Button } from '@/components/ui/button'

defineProps({ recolhido: { type: Boolean, default: false } })
const emit = defineEmits(['recolher'])
</script>

<template>
  <Button variant="ghost" class="w-full" :class="recolhido ? 'justify-center px-0' : 'justify-start'" @click="emit('recolher')">
    <PanelLeft /><span v-if="!recolhido">Recolher menu</span>
  </Button>
</template>
