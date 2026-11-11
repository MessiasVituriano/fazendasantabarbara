<script setup>
import { reactive, watch } from 'vue'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import http from '../api/http'
import AlertaErros from './AlertaErros.vue'
import { useCadastrosFinanceiros } from '../composables/useCadastrosFinanceiros'
import { useEnvio } from '../composables/useEnvio'
import { hoje } from '../formatos'

/** Criar (sem `lote`) ou editar (com `lote`) um lote de recria/engorda. */
const props = defineProps({ lote: { type: Object, default: null } })
const open = defineModel('open', { type: Boolean, default: false })
const emit = defineEmits(['salvo'])

const { recarregar } = useCadastrosFinanceiros()
const { enviando, mensagens, campos, enviar } = useEnvio()
const form = reactive({})

watch(open, (aberto) => {
  if (!aberto) return
  const l = props.lote
  mensagens.value = []
  campos.value = {}
  Object.assign(form, l
    ? { nome: l.nome, data_formacao: l.data_formacao, categoria: l.categoria ?? '', origem: l.origem ?? '', observacoes: l.observacoes ?? '', data_encerramento: l.data_encerramento ?? '' }
    : { nome: '', data_formacao: hoje(), categoria: '', origem: '', observacoes: '', data_encerramento: '' })
}, { immediate: true })

async function salvar() {
  const dados = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
  try {
    const { data } = await enviar(() => props.lote ? http.patch(`/lotes/${props.lote.id}`, dados) : http.post('/lotes', dados))
    toast.success(props.lote ? 'Lote atualizado' : `Lote ${data.data.nome} criado`)
    open.value = false
    recarregar()
    emit('salvo', data.data)
  } catch { /* exibido no formulário */ }
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ lote ? 'Editar lote' : 'Novo lote' }}</DialogTitle>
        <DialogDescription>Encerre o lote (data de encerramento) quando for vendido ou desfeito: o histórico é mantido.</DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />
      <form id="form-lote" class="grid gap-4 sm:grid-cols-2" @submit.prevent="salvar">
        <div class="grid gap-1.5">
          <Label for="lt-nome">Nome</Label>
          <Input id="lt-nome" v-model.trim="form.nome" required maxlength="80" placeholder="Ex.: Lote 01 Nelore" :aria-invalid="!!campos.nome" />
          <p v-if="campos.nome" class="text-xs text-destructive">{{ campos.nome }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="lt-form">Data de formação</Label>
          <Input id="lt-form" v-model="form.data_formacao" type="date" required :aria-invalid="!!campos.data_formacao" />
          <p v-if="campos.data_formacao" class="text-xs text-destructive">{{ campos.data_formacao }}</p>
        </div>
        <div class="grid gap-1.5">
          <Label for="lt-cat">Categoria</Label>
          <Input id="lt-cat" v-model="form.categoria" maxlength="60" placeholder="Ex.: Garrotes de recria" />
        </div>
        <div class="grid gap-1.5">
          <Label for="lt-orig">Origem</Label>
          <Input id="lt-orig" v-model="form.origem" maxlength="120" placeholder="Ex.: Leilão de Araguaína" />
        </div>
        <div class="grid gap-1.5 sm:col-span-2">
          <Label for="lt-obs">Observações</Label>
          <Textarea id="lt-obs" v-model="form.observacoes" rows="2" />
        </div>
        <div v-if="lote" class="grid gap-1.5">
          <Label for="lt-enc">Data de encerramento</Label>
          <Input id="lt-enc" v-model="form.data_encerramento" type="date" :min="form.data_formacao" :aria-invalid="!!campos.data_encerramento" />
          <p v-if="campos.data_encerramento" class="text-xs text-destructive">{{ campos.data_encerramento }}</p>
        </div>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="open = false">Cancelar</Button>
        <Button type="submit" form="form-lote" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
