<script setup>
import { CircleAlert } from '@lucide/vue'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'

defineProps({ mensagens: { type: Array, default: () => [] } })
</script>

<template>
  <Alert v-if="mensagens.length" variant="destructive">
    <CircleAlert />
    <AlertTitle>{{ mensagens.length === 1 ? mensagens[0] : 'Revise os dados' }}</AlertTitle>
    <AlertDescription v-if="mensagens.length > 1">
      <ul class="list-disc pl-4"><li v-for="(m, i) in mensagens" :key="i">{{ m }}</li></ul>
    </AlertDescription>
  </Alert>
</template>
