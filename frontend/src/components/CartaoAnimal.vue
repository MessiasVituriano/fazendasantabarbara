<script setup>
import { computed } from 'vue'
import { Baby, DropletOff, MapPin, Mars, Stethoscope, TriangleAlert, Venus } from '@lucide/vue'
import { Badge } from '@/components/ui/badge'
import { Card } from '@/components/ui/card'
import { dataBr, idade } from '../formatos'
import AvatarAnimal from './AvatarAnimal.vue'
import { corReprodutivo } from './cores'

const props = defineProps({ animal: { type: Object, required: true } })

const a = computed(() => props.animal)
const femea = computed(() => a.value.sexo === 'F')
const mostrarReprodutivo = computed(() => femea.value && a.value.categoria !== 'BEZERRO')
const proximaData = computed(() => [
  ['Toque', a.value.data_prevista_toque, Stethoscope],
  ['Secagem', a.value.data_prevista_secagem, DropletOff],
  ['Parto', a.value.data_provavel_parto, Baby],
].filter(([, d]) => d).sort(([, x], [, y]) => x.localeCompare(y))[0])
</script>

<template>
  <RouterLink :to="`/animais/${a.id}`" class="group rounded-xl focus-visible:outline-2 focus-visible:outline-ring">
    <Card class="h-full gap-0 py-0 transition hover:shadow-md" :class="a.em_carencia ? 'border-destructive/50' : ''">
      <div class="flex gap-3 p-4">
        <AvatarAnimal :animal="a" :tamanho="52" />
        <div class="min-w-0 flex-1">
          <div class="flex items-center justify-between gap-2">
            <p class="truncate font-semibold tracking-tight">{{ a.nome || a.brinco }}</p>
            <component :is="femea ? Venus : Mars" class="size-4 shrink-0" :class="femea ? 'text-pink-500' : 'text-sky-500'" />
          </div>
          <Badge v-if="a.nome" variant="outline" class="mt-1 font-mono">{{ a.brinco }}</Badge>
          <p class="mt-1.5 text-xs text-muted-foreground">{{ idade(a.data_nascimento) }} · {{ a.aptidao_label }}</p>
        </div>
      </div>

      <div class="flex flex-wrap gap-1.5 px-4 pb-3">
        <Badge variant="secondary">{{ a.categoria_label }}</Badge>
        <Badge v-if="mostrarReprodutivo" class="border-transparent" :class="corReprodutivo[a.status_reprodutivo]">{{ a.status_reprodutivo_label }}</Badge>
        <Badge v-if="a.status_geral !== 'ATIVO'">{{ a.status_geral_label }}</Badge>
      </div>

      <div class="mt-auto space-y-1 rounded-b-xl border-t bg-muted/40 px-4 py-2.5 text-xs text-muted-foreground">
        <p v-if="a.em_carencia" class="flex items-center gap-1.5 font-medium text-destructive">
          <TriangleAlert class="size-3.5" /> Carência até {{ dataBr(a.carencia_ate) }}
        </p>
        <p v-if="proximaData" class="flex items-center gap-1.5">
          <component :is="proximaData[2]" class="size-3.5" /> {{ proximaData[0] }} previsto:
          <span class="font-medium text-foreground">{{ dataBr(proximaData[1]) }}</span>
        </p>
        <p class="flex items-center gap-1.5">
          <MapPin class="size-3.5" /> {{ a.piquete_atual ? `Piquete ${a.piquete_atual.identificacao}` : 'Sem piquete' }}
        </p>
      </div>
    </Card>
  </RouterLink>
</template>
