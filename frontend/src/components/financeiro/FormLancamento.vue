<script setup>
import { computed, ref, watch } from 'vue'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import http from '../../api/http'
import { useCadastrosFinanceiros } from '../../composables/useCadastrosFinanceiros'
import { useEnvio } from '../../composables/useEnvio'
import { useOpcoes } from '../../composables/useOpcoes'
import { hoje, moeda, valorParaApi } from '../../formatos'
import AlertaErros from '../AlertaErros.vue'

/**
 * Novo lançamento (avulso, já pago ou parcelado) ou edição.
 * Em lançamento pago, valor e dados do pagamento ficam travados: a API exige estorno antes.
 */
const aberto = defineModel('open', { type: Boolean, default: false })
const props = defineProps({
  lancamento: { type: Object, default: null }, // edição
  padrao: { type: Object, default: () => ({}) }, // ex.: { tipo: 'RECEITA', lote_id: 3 }
})
const emit = defineEmits(['salvo'])

const NENHUM = '_'
const { opcoes } = useOpcoes()
const { categoriasDo, contasAtivas, pessoas, lotes } = useCadastrosFinanceiros()
const { enviando, mensagens, campos, enviar } = useEnvio()

const vazio = () => ({
  tipo: 'DESPESA', descricao: '', categoria_id: null, valor: '', data_competencia: hoje(), data_vencimento: hoje(),
  pessoa_id: NENHUM, lote_id: NENHUM, documento: '', observacoes: '', parcelas: 1,
  pago: false, data_pagamento: hoje(), conta_financeira_id: null, forma_pagamento: 'PIX', valor_pago: '',
})
const form = ref(vazio())
const editando = computed(() => !!props.lancamento)
const travado = computed(() => props.lancamento?.situacao === 'PAGO')

watch(aberto, (v) => {
  if (!v) return
  mensagens.value = []
  campos.value = {}
  const l = props.lancamento
  form.value = l
    ? {
        ...vazio(), ...l,
        valor: String(l.valor).replace('.', ','),
        categoria_id: String(l.categoria_id),
        pessoa_id: l.pessoa_id ? String(l.pessoa_id) : NENHUM,
        lote_id: l.lote_id ? String(l.lote_id) : NENHUM,
        documento: l.documento ?? '', observacoes: l.observacoes ?? '',
      }
    : { ...vazio(), ...Object.fromEntries(Object.entries(props.padrao).map(([k, v]) => [k, typeof v === 'number' ? String(v) : v])) }
  if (!form.value.conta_financeira_id && contasAtivas.value.length === 1) form.value.conta_financeira_id = String(contasAtivas.value[0].id)
})

const categorias = computed(() => categoriasDo(form.value.tipo))
// Lote encerrado continua aparecendo se já estiver no lançamento.
const opcoesLote = computed(() => lotes.value.filter((l) => !l.data_encerramento || String(l.id) === form.value.lote_id))
const pessoasDoTipo = computed(() => pessoas.value.filter((p) => p.ativo || String(p.id) === form.value.pessoa_id)
  .filter((p) => p.tipo === 'AMBOS' || p.tipo === (form.value.tipo === 'RECEITA' ? 'CLIENTE' : 'FORNECEDOR')))
watch(() => form.value.tipo, () => {
  if (!categorias.value.some((c) => String(c.id) === form.value.categoria_id)) form.value.categoria_id = null
})
const valorParcela = computed(() => {
  const v = Number(valorParaApi(form.value.valor))
  const n = Number(form.value.parcelas)
  return v > 0 && n > 1 ? v / n : null
})

async function salvar() {
  const f = form.value
  const corpo = {
    tipo: f.tipo, descricao: f.descricao, categoria_id: Number(f.categoria_id), valor: valorParaApi(f.valor),
    data_competencia: f.data_competencia, data_vencimento: f.data_vencimento,
    pessoa_id: f.pessoa_id === NENHUM ? null : Number(f.pessoa_id),
    lote_id: f.lote_id === NENHUM ? null : Number(f.lote_id),
    documento: f.documento || null, observacoes: f.observacoes || null,
  }
  if (!editando.value) {
    if (Number(f.parcelas) > 1) corpo.parcelas = Number(f.parcelas)
    if (f.pago) Object.assign(corpo, {
      data_pagamento: f.data_pagamento, conta_financeira_id: Number(f.conta_financeira_id) || null,
      forma_pagamento: f.forma_pagamento, valor_pago: valorParaApi(f.valor_pago),
    })
  }
  try {
    const { data } = await enviar(() => editando.value
      ? http.patch(`/financeiro/lancamentos/${props.lancamento.id}`, corpo)
      : http.post('/financeiro/lancamentos', corpo))
    const qtd = Array.isArray(data.data) ? data.data.length : 1
    toast.success(editando.value ? 'Lançamento atualizado' : qtd > 1 ? `${qtd} parcelas lançadas` : 'Lançamento salvo')
    aberto.value = false
    emit('salvo', data.data)
  } catch { /* erros exibidos no formulário */ }
}
const erro = (c) => campos.value[c]
</script>

<template>
  <Dialog v-model:open="aberto">
    <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>{{ editando ? 'Editar lançamento' : 'Novo lançamento' }}</DialogTitle>
        <DialogDescription>
          Competência é quando a despesa/receita aconteceu; vencimento é quando deve ser paga.
        </DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />

      <form id="form-lancamento" class="grid gap-4 sm:grid-cols-6" @submit.prevent="salvar">
        <div class="sm:col-span-6">
          <ToggleGroup v-model="form.tipo" type="single" variant="outline" :disabled="travado" class="w-full" aria-label="Tipo">
            <ToggleGroupItem value="DESPESA" class="flex-1 data-[state=on]:bg-red-600 data-[state=on]:text-white">Despesa (saída)</ToggleGroupItem>
            <ToggleGroupItem value="RECEITA" class="flex-1 data-[state=on]:bg-emerald-600 data-[state=on]:text-white">Receita (entrada)</ToggleGroupItem>
          </ToggleGroup>
        </div>

        <div class="grid gap-1.5 sm:col-span-4">
          <Label for="l-desc">Descrição</Label>
          <Input id="l-desc" v-model="form.descricao" required maxlength="160" placeholder="Ex.: 40 sacos de sal mineral" :aria-invalid="!!erro('descricao')" />
          <p v-if="erro('descricao')" class="text-xs text-destructive">{{ erro('descricao') }}</p>
        </div>
        <div class="grid gap-1.5 sm:col-span-2">
          <Label for="l-valor">Valor {{ Number(form.parcelas) > 1 ? 'total' : '' }} (R$)</Label>
          <Input id="l-valor" v-model="form.valor" inputmode="decimal" required placeholder="0,00" :disabled="travado" :aria-invalid="!!erro('valor')" />
          <p v-if="erro('valor')" class="text-xs text-destructive">{{ erro('valor') }}</p>
        </div>

        <div class="grid gap-1.5 sm:col-span-3">
          <Label>Categoria</Label>
          <Select v-model="form.categoria_id" required>
            <SelectTrigger class="w-full" :aria-invalid="!!erro('categoria_id')"><SelectValue placeholder="Escolha a categoria" /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="c in categorias" :key="c.id" :value="String(c.id)">{{ c.nome }}</SelectItem>
            </SelectContent>
          </Select>
          <p v-if="erro('categoria_id')" class="text-xs text-destructive">{{ erro('categoria_id') }}</p>
        </div>
        <div class="grid gap-1.5 sm:col-span-3">
          <Label>{{ form.tipo === 'RECEITA' ? 'Cliente/comprador' : 'Fornecedor' }}</Label>
          <Select v-model="form.pessoa_id">
            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="NENHUM">Não informado</SelectItem>
              <SelectItem v-for="p in pessoasDoTipo" :key="p.id" :value="String(p.id)">{{ p.nome }}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="grid gap-1.5 sm:col-span-2">
          <Label for="l-comp">Competência</Label>
          <Input id="l-comp" v-model="form.data_competencia" type="date" required :aria-invalid="!!erro('data_competencia')" />
        </div>
        <div class="grid gap-1.5 sm:col-span-2">
          <Label for="l-venc">{{ Number(form.parcelas) > 1 ? '1º vencimento' : 'Vencimento' }}</Label>
          <Input id="l-venc" v-model="form.data_vencimento" type="date" required :aria-invalid="!!erro('data_vencimento')" />
        </div>
        <div class="grid gap-1.5 sm:col-span-2">
          <Label>Lote</Label>
          <Select v-model="form.lote_id">
            <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="NENHUM">Sem lote (geral)</SelectItem>
              <SelectItem v-for="l in opcoesLote" :key="l.id" :value="String(l.id)">{{ l.nome }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <p v-if="form.tipo === 'DESPESA' && form.lote_id !== NENHUM" class="-mt-2 text-xs text-muted-foreground sm:col-span-6">
          Esta despesa entra automaticamente no custo do lote.
        </p>

        <template v-if="!editando">
          <div class="grid gap-1.5 sm:col-span-2">
            <Label for="l-parc">Parcelas</Label>
            <Input id="l-parc" v-model="form.parcelas" type="number" min="1" max="120" :disabled="form.pago" />
          </div>
          <p class="self-end pb-2 text-sm text-muted-foreground sm:col-span-4">
            <template v-if="valorParcela">{{ form.parcelas }}× de {{ moeda(valorParcela) }}, vencimentos mensais</template>
          </p>

          <label v-if="Number(form.parcelas) <= 1" class="flex items-center gap-2 text-sm font-medium sm:col-span-6">
            <input v-model="form.pago" type="checkbox" class="size-4 accent-primary" />
            Já foi {{ form.tipo === 'RECEITA' ? 'recebido' : 'pago' }}
          </label>
          <template v-if="form.pago">
            <div class="grid gap-1.5 sm:col-span-2">
              <Label for="l-pag">Data do pagamento</Label>
              <Input id="l-pag" v-model="form.data_pagamento" type="date" :max="hoje()" required :aria-invalid="!!erro('data_pagamento')" />
              <p v-if="erro('data_pagamento')" class="text-xs text-destructive">{{ erro('data_pagamento') }}</p>
            </div>
            <div class="grid gap-1.5 sm:col-span-2">
              <Label>Conta</Label>
              <Select v-model="form.conta_financeira_id" required>
                <SelectTrigger class="w-full" :aria-invalid="!!erro('conta_financeira_id')"><SelectValue placeholder="Caixa ou banco" /></SelectTrigger>
                <SelectContent><SelectItem v-for="c in contasAtivas" :key="c.id" :value="String(c.id)">{{ c.nome }}</SelectItem></SelectContent>
              </Select>
              <p v-if="erro('conta_financeira_id')" class="text-xs text-destructive">{{ erro('conta_financeira_id') }}</p>
            </div>
            <div class="grid gap-1.5 sm:col-span-2">
              <Label>Forma</Label>
              <Select v-model="form.forma_pagamento">
                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                <SelectContent><SelectItem v-for="f in opcoes?.forma_pagamento" :key="f.value" :value="f.value">{{ f.label }}</SelectItem></SelectContent>
              </Select>
            </div>
          </template>
        </template>

        <div class="grid gap-1.5 sm:col-span-2">
          <Label for="l-doc">Nº do documento</Label>
          <Input id="l-doc" v-model="form.documento" maxlength="60" placeholder="NF, boleto, recibo" />
        </div>
        <div class="grid gap-1.5 sm:col-span-4">
          <Label for="l-obs">Observações</Label>
          <Textarea id="l-obs" v-model="form.observacoes" rows="2" />
        </div>
        <p v-if="travado" class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground sm:col-span-6">
          Lançamento já pago: valor, tipo e dados do pagamento só mudam depois de estornar o pagamento.
        </p>
      </form>

      <DialogFooter>
        <Button variant="ghost" @click="aberto = false">Cancelar</Button>
        <Button type="submit" form="form-lancamento" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
