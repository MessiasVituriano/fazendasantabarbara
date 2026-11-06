<script setup>
import { computed, ref, watch } from 'vue'
import { Input } from '@/components/ui/input'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { hoje } from '../formatos'

/**
 * Seletor de período: atalhos (7/30/90 dias) ou intervalo personalizado.
 * v-model = { inicio, fim } (AAAA-MM-DD).
 */
const modelo = defineModel({ type: Object, required: true })
const props = defineProps({ atalhos: { type: Array, default: () => [7, 30, 90] }, personalizado: { type: Boolean, default: true } })

const menosDias = (n) => {
  const d = new Date(`${hoje()}T12:00:00`)
  d.setDate(d.getDate() - (n - 1))
  return d.toISOString().slice(0, 10)
}
const diasDoModelo = () => {
  if (modelo.value.fim !== hoje()) return 'custom'
  return props.atalhos.find((n) => menosDias(n) === modelo.value.inicio) ?? 'custom'
}
const escolha = ref(String(diasDoModelo()))
const inicio = ref(modelo.value.inicio)
const fim = ref(modelo.value.fim)

watch(escolha, (v) => {
  if (!v) return (escolha.value = String(diasDoModelo())) // impede "desmarcar"
  if (v !== 'custom') modelo.value = { inicio: menosDias(Number(v)), fim: hoje() }
})
watch([inicio, fim], ([i, f]) => {
  if (escolha.value === 'custom' && i && f && i <= f) modelo.value = { inicio: i, fim: f }
})
const invalido = computed(() => escolha.value === 'custom' && inicio.value && fim.value && inicio.value > fim.value)
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <ToggleGroup v-model="escolha" type="single" variant="outline" size="sm" aria-label="Período">
      <ToggleGroupItem v-for="n in atalhos" :key="n" :value="String(n)" class="px-2 sm:px-3 data-[state=on]:bg-primary data-[state=on]:text-primary-foreground data-[state=on]:hover:bg-primary/90">{{ n }} dias</ToggleGroupItem>
      <ToggleGroupItem v-if="personalizado" value="custom" class="px-2 sm:px-3 data-[state=on]:bg-primary data-[state=on]:text-primary-foreground data-[state=on]:hover:bg-primary/90">Personalizado</ToggleGroupItem>
    </ToggleGroup>
    <template v-if="escolha === 'custom'">
      <Input v-model="inicio" type="date" :max="fim || hoje()" class="h-8 w-38" aria-label="Data inicial" :aria-invalid="invalido" />
      <span class="text-sm text-muted-foreground">a</span>
      <Input v-model="fim" type="date" :min="inicio" :max="hoje()" class="h-8 w-38" aria-label="Data final" :aria-invalid="invalido" />
    </template>
  </div>
</template>
