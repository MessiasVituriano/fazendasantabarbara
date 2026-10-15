<script setup>
import { computed, reactive } from 'vue'
import { Baby } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import http from '../api/http'
import { useEnvio } from '../composables/useEnvio'
import { useOpcoes } from '../composables/useOpcoes'
import { useDataMinima } from '../composables/useDataMinima'
import { hoje } from '../formatos'
import AlertaErros from './AlertaErros.vue'

/**
 * RN02 — Formulário inteligente de evento reprodutivo.
 * Ao escolher PARTO, os campos do bezerro são inseridos no DOM (v-if) e passam a ser obrigatórios.
 */
const props = defineProps({ animalId: { type: Number, required: true } })
const emit = defineEmits(['salvo', 'cancelar'])

const { opcoes } = useOpcoes()
const dataMinima = useDataMinima()
const { enviando, mensagens, campos, enviar } = useEnvio()

const form = reactive({
  animal_id: props.animalId, tipo_evento: 'INSEMINACAO', data_evento: hoje(), observacoes: '',
  brinco_bezerro: '', sexo_bezerro: '', nome_bezerro: '',
})
const ehParto = computed(() => form.tipo_evento === 'PARTO')
const erro = (campo) => campos.value[`registros.0.${campo}`]
const mensagensGerais = computed(() => (Object.keys(campos.value).length ? [] : mensagens.value))

async function salvar() {
  const { brinco_bezerro, sexo_bezerro, nome_bezerro, ...evento } = form
  // Dados do bezerro só seguem no PARTO (a API os rejeita nos demais eventos).
  const payload = ehParto.value ? { ...evento, brinco_bezerro, sexo_bezerro, nome_bezerro: nome_bezerro || null } : evento
  const { data } = await enviar(() => http.post('/eventos-reprodutivos', payload))
  emit('salvo', data.data[0])
}
</script>

<template>
  <form class="grid gap-4" @submit.prevent="salvar().catch(() => {})">
    <AlertaErros :mensagens="mensagensGerais" />

    <div class="grid gap-4 sm:grid-cols-2">
      <div class="grid gap-1.5">
        <Label>Tipo de evento</Label>
        <Select v-model="form.tipo_evento">
          <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem v-for="t in opcoes?.tipo_evento" :key="t.value" :value="t.value">{{ t.label }}</SelectItem>
          </SelectContent>
        </Select>
        <p v-if="erro('tipo_evento')" class="text-xs text-destructive">{{ erro('tipo_evento') }}</p>
      </div>
      <div class="grid gap-1.5">
        <Label for="data_evento">Data do evento</Label>
        <Input id="data_evento" v-model="form.data_evento" type="date" :min="dataMinima" :max="hoje()" required :aria-invalid="!!erro('data_evento')" />
        <p v-if="erro('data_evento')" class="text-xs text-destructive">{{ erro('data_evento') }}</p>
      </div>
    </div>

    <fieldset v-if="ehParto" class="grid gap-4 rounded-lg border border-amber-300 bg-amber-50 p-4 sm:grid-cols-2 dark:border-amber-800 dark:bg-amber-950/40">
      <legend class="flex items-center gap-1.5 px-1 text-sm font-medium text-amber-800 dark:text-amber-300"><Baby class="size-4" /> Bezerro(a) nascido(a)</legend>
      <div class="grid gap-1.5">
        <Label for="brinco_bezerro">Brinco do bezerro *</Label>
        <Input id="brinco_bezerro" v-model.trim="form.brinco_bezerro" required maxlength="50" :aria-invalid="!!erro('brinco_bezerro')" />
        <p v-if="erro('brinco_bezerro')" class="text-xs text-destructive">{{ erro('brinco_bezerro') }}</p>
      </div>
      <div class="grid gap-1.5">
        <Label>Sexo *</Label>
        <Select v-model="form.sexo_bezerro" required>
          <SelectTrigger class="w-full" :aria-invalid="!!erro('sexo_bezerro')"><SelectValue placeholder="Selecione…" /></SelectTrigger>
          <SelectContent>
            <SelectItem v-for="s in opcoes?.sexo" :key="s.value" :value="s.value">{{ s.label }}</SelectItem>
          </SelectContent>
        </Select>
        <p v-if="erro('sexo_bezerro')" class="text-xs text-destructive">{{ erro('sexo_bezerro') }}</p>
      </div>
      <div class="grid gap-1.5 sm:col-span-2">
        <Label for="nome_bezerro">Nome (opcional)</Label>
        <Input id="nome_bezerro" v-model.trim="form.nome_bezerro" maxlength="100" />
      </div>
    </fieldset>

    <div class="grid gap-1.5">
      <Label for="observacoes">Observações</Label>
      <Textarea id="observacoes" v-model="form.observacoes" rows="2" />
    </div>

    <div class="flex justify-end gap-2">
      <Button type="button" variant="ghost" @click="emit('cancelar')">Cancelar</Button>
      <Button type="submit" :disabled="enviando">{{ enviando ? 'Salvando…' : 'Registrar evento' }}</Button>
    </div>
  </form>
</template>
