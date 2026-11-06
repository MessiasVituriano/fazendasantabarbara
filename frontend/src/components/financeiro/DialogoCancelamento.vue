<script setup>
import { ref, watch } from 'vue'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import http from '../../api/http'
import { useEnvio } from '../../composables/useEnvio'
import AlertaErros from '../AlertaErros.vue'

/** Cancela um lançamento em aberto, com motivo obrigatório. Nada é apagado: ele continua consultável no histórico. */
const aberto = defineModel('open', { type: Boolean, default: false })
const props = defineProps({ lancamento: { type: Object, default: null } })
const emit = defineEmits(['cancelado'])

const { enviando, mensagens, enviar } = useEnvio()
const motivo = ref('')
const parcelasSeguintes = ref(false)

watch(aberto, (v) => {
  if (!v) return
  mensagens.value = []
  motivo.value = ''
  parcelasSeguintes.value = false
})

async function cancelar() {
  try {
    const { data } = await enviar(() => http.post(`/financeiro/lancamentos/${props.lancamento.id}/cancelamento`, {
      motivo: motivo.value, parcelas_seguintes: parcelasSeguintes.value,
    }))
    toast.success(data.data.length > 1 ? `${data.data.length} parcelas canceladas` : 'Lançamento cancelado')
    aberto.value = false
    emit('cancelado')
  } catch { /* exibido no diálogo */ }
}
</script>

<template>
  <Dialog v-model:open="aberto">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Cancelar lançamento</DialogTitle>
        <DialogDescription>
          <template v-if="lancamento">"{{ lancamento.descricao }}". </template>Cancelados saem de todos os totais, mas continuam consultáveis no histórico.
        </DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />
      <div class="grid gap-1.5">
        <Label for="m-canc">Motivo</Label>
        <Input id="m-canc" v-model="motivo" maxlength="255" required placeholder="Ex.: lançado em duplicidade" />
      </div>
      <label v-if="lancamento?.total_parcelas && lancamento.parcela < lancamento.total_parcelas" class="flex items-center gap-2 text-sm">
        <input v-model="parcelasSeguintes" type="checkbox" class="size-4 accent-primary" />
        Cancelar também as parcelas seguintes em aberto
      </label>
      <DialogFooter>
        <Button variant="ghost" @click="aberto = false">Voltar</Button>
        <Button variant="destructive" :disabled="enviando || !motivo.trim()" @click="cancelar">Cancelar lançamento</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
