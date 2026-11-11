<script setup>
import { ref } from 'vue'
import { Check, Copy, MessageCircle } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

/** Link gerado pela API (convite, nova senha) para copiar ou mandar pelo WhatsApp. */
const props = defineProps({
  link: { type: String, required: true },
  whatsapp: { type: String, default: null },
  aviso: { type: String, default: null },
})

const copiado = ref(false)
async function copiar() {
  try {
    await navigator.clipboard.writeText(props.link)
    copiado.value = true
    setTimeout(() => (copiado.value = false), 2000)
  } catch { /* sem permissão: o campo fica selecionável */ }
}
</script>

<template>
  <div class="grid gap-2">
    <div class="flex gap-2">
      <Input :model-value="link" readonly class="font-mono text-xs" aria-label="Link" @focus="(e) => e.target.select()" />
      <Button type="button" variant="outline" size="icon" :aria-label="copiado ? 'Copiado' : 'Copiar link'" @click="copiar">
        <component :is="copiado ? Check : Copy" />
      </Button>
    </div>
    <Button v-if="whatsapp" as="a" :href="whatsapp" target="_blank" rel="noopener" class="bg-emerald-600 text-white hover:bg-emerald-700">
      <MessageCircle /> Enviar pelo WhatsApp
    </Button>
    <p v-if="aviso" class="text-xs text-muted-foreground">{{ aviso }}</p>
  </div>
</template>
