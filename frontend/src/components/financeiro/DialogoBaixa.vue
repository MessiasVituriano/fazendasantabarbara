<script setup>
import { computed, ref, watch } from 'vue'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import http from '../../api/http'
import { useCadastrosFinanceiros } from '../../composables/useCadastrosFinanceiros'
import { useEnvio } from '../../composables/useEnvio'
import { useOpcoes } from '../../composables/useOpcoes'
import { hoje, moeda, valorParaApi } from '../../formatos'
import AlertaErros from '../AlertaErros.vue'

/**
 * Baixa de pagamento/recebimento. Com um lançamento, permite ajustar o valor pago (juros, multa, desconto);
 * com vários, cada um é baixado pelo próprio valor, tudo ou nada.
 */
const aberto = defineModel('open', { type: Boolean, default: false })
const props = defineProps({ lancamentos: { type: Array, default: () => [] } })
const emit = defineEmits(['baixado'])

const { opcoes } = useOpcoes()
const { contasAtivas } = useCadastrosFinanceiros()
const { enviando, mensagens, campos, enviar } = useEnvio()
const form = ref({})

const unico = computed(() => props.lancamentos.length === 1 ? props.lancamentos[0] : null)
const total = computed(() => props.lancamentos.reduce((s, l) => s + Number(l.valor), 0))
const tipos = computed(() => new Set(props.lancamentos.map((l) => l.tipo)))
const verbo = computed(() => tipos.value.size === 1 && tipos.value.has('RECEITA') ? 'recebimento' : 'pagamento')

watch(aberto, (v) => {
  if (!v) return
  mensagens.value = []
  campos.value = {}
  form.value = {
    data_pagamento: hoje(),
    conta_financeira_id: contasAtivas.value.length === 1 ? String(contasAtivas.value[0].id) : null,
    forma_pagamento: 'PIX',
    valor_pago: unico.value ? String(unico.value.valor).replace('.', ',') : '',
  }
})

const diferenca = computed(() => {
  if (!unico.value) return 0
  const pago = Number(valorParaApi(form.value.valor_pago))
  return pago ? pago - Number(unico.value.valor) : 0
})

async function confirmar() {
  const corpo = {
    data_pagamento: form.value.data_pagamento,
    conta_financeira_id: Number(form.value.conta_financeira_id) || null,
    forma_pagamento: form.value.forma_pagamento,
  }
  try {
    if (unico.value) {
      await enviar(() => http.post(`/financeiro/lancamentos/${unico.value.id}/baixa`, { ...corpo, valor_pago: valorParaApi(form.value.valor_pago) }))
    } else {
      await enviar(() => http.post('/financeiro/lancamentos/baixa-em-lote', { ...corpo, ids: props.lancamentos.map((l) => l.id) }))
    }
    toast.success(unico.value ? `Baixa registrada: ${unico.value.descricao}` : `${props.lancamentos.length} lançamentos baixados`)
    aberto.value = false
    emit('baixado')
  } catch { /* exibido no diálogo */ }
}
</script>

<template>
  <Dialog v-model:open="aberto">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Registrar {{ verbo }}</DialogTitle>
        <DialogDescription>
          <template v-if="unico">{{ unico.descricao }} · vence {{ unico.data_vencimento.split('-').reverse().join('/') }}</template>
          <template v-else>{{ lancamentos.length }} lançamentos · total {{ moeda(total) }}</template>
        </DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />

      <form id="form-baixa" class="grid gap-4 sm:grid-cols-2" @submit.prevent="confirmar">
        <div class="grid gap-1.5">
          <Label for="b-data">Data do {{ verbo }}</Label>
          <Input id="b-data" v-model="form.data_pagamento" type="date" :max="hoje()" required :aria-invalid="!!campos.data_pagamento" />
        </div>
        <div v-if="unico" class="grid gap-1.5">
          <Label for="b-valor">Valor {{ verbo === 'recebimento' ? 'recebido' : 'pago' }} (R$)</Label>
          <Input id="b-valor" v-model="form.valor_pago" inputmode="decimal" required :aria-invalid="!!campos.valor_pago" />
        </div>
        <div class="grid gap-1.5">
          <Label>Conta</Label>
          <Select v-model="form.conta_financeira_id" required>
            <SelectTrigger class="w-full" :aria-invalid="!!campos.conta_financeira_id"><SelectValue placeholder="Caixa ou banco" /></SelectTrigger>
            <SelectContent><SelectItem v-for="c in contasAtivas" :key="c.id" :value="String(c.id)">{{ c.nome }}</SelectItem></SelectContent>
          </Select>
        </div>
        <div class="grid gap-1.5">
          <Label>Forma</Label>
          <Select v-model="form.forma_pagamento">
            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
            <SelectContent><SelectItem v-for="f in opcoes?.forma_pagamento" :key="f.value" :value="f.value">{{ f.label }}</SelectItem></SelectContent>
          </Select>
        </div>
        <p v-if="Math.abs(diferenca) >= 0.01" class="text-xs text-muted-foreground sm:col-span-2">
          {{ diferenca > 0 ? 'Acréscimo (juros/multa)' : 'Desconto' }} de {{ moeda(Math.abs(diferenca)) }} em relação ao valor original.
        </p>
        <p v-if="!contasAtivas.length" class="text-xs text-destructive sm:col-span-2">
          Cadastre uma conta (caixa ou banco) em Financeiro › Contas e categorias.
        </p>
      </form>

      <DialogFooter>
        <Button variant="ghost" @click="aberto = false">Cancelar</Button>
        <Button type="submit" form="form-baixa" :disabled="enviando">Confirmar {{ verbo }}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
