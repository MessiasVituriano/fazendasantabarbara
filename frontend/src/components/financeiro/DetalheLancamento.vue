<script setup>
import { ref, watch } from 'vue'
import { Ban, CircleCheck, Download, History, Paperclip, Pencil, Trash2, Undo2 } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import http from '../../api/http'
import { useEnvio } from '../../composables/useEnvio'
import { dataBr, moeda } from '../../formatos'
import AlertaErros from '../AlertaErros.vue'
import DialogoBaixa from './DialogoBaixa.vue'
import FormLancamento from './FormLancamento.vue'
import SeloSituacao from './SeloSituacao.vue'

/** Ficha do lançamento: dados, baixa/estorno/cancelamento, comprovantes e histórico de alterações. */
const props = defineProps({ id: { type: Number, default: null } })
const emit = defineEmits(['fechar', 'alterado'])

const l = ref(null)
const historico = ref([])
const verHistorico = ref(false)
const erros = ref([])
const { enviando, mensagens, enviar } = useEnvio()

async function carregar() {
  if (!props.id) return
  try {
    const [d, h] = await Promise.all([
      http.get(`/financeiro/lancamentos/${props.id}`),
      http.get(`/financeiro/lancamentos/${props.id}/historico`),
    ])
    l.value = d.data.data
    historico.value = h.data.data
    erros.value = []
  } catch (e) { erros.value = e.mensagens }
}
watch(() => props.id, (id) => { l.value = null; verHistorico.value = false; if (id) carregar() }, { immediate: true })

function alterou() {
  carregar()
  emit('alterado')
}

const editar = ref(false)
const baixar = ref(false)

// Estorno
const estornoAberto = ref(false)
const motivoEstorno = ref('')
async function estornar() {
  try {
    await enviar(() => http.post(`/financeiro/lancamentos/${l.value.id}/estorno`, { motivo: motivoEstorno.value || null }))
    toast.success('Pagamento estornado: o lançamento voltou a ficar em aberto')
    estornoAberto.value = false
    motivoEstorno.value = ''
    alterou()
  } catch { /* exibido no diálogo */ }
}

// Cancelamento
const cancelarAberto = ref(false)
const motivoCancelamento = ref('')
const parcelasSeguintes = ref(false)
async function cancelar() {
  try {
    const { data } = await enviar(() => http.post(`/financeiro/lancamentos/${l.value.id}/cancelamento`, {
      motivo: motivoCancelamento.value, parcelas_seguintes: parcelasSeguintes.value,
    }))
    toast.success(data.data.length > 1 ? `${data.data.length} parcelas canceladas` : 'Lançamento cancelado')
    cancelarAberto.value = false
    motivoCancelamento.value = ''
    alterou()
  } catch { /* exibido no diálogo */ }
}

// Anexos
const entrada = ref(null)
const enviandoAnexo = ref(false)
async function anexar(evento) {
  const arquivo = evento.target.files?.[0]
  evento.target.value = ''
  if (!arquivo) return
  enviandoAnexo.value = true
  try {
    const dados = new FormData()
    dados.append('arquivo', arquivo)
    await http.post(`/financeiro/lancamentos/${l.value.id}/anexos`, dados)
    toast.success('Comprovante anexado')
    alterou()
  } catch (e) {
    toast.error(e.mensagens?.[0] ?? 'Não foi possível anexar')
  } finally { enviandoAnexo.value = false }
}
async function baixarAnexo(a) {
  try {
    const { data } = await http.get(`/financeiro/anexos/${a.id}`, { responseType: 'blob' })
    const url = URL.createObjectURL(data)
    const link = Object.assign(document.createElement('a'), { href: url, download: a.nome_original })
    link.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch { toast.error('Não foi possível baixar o arquivo') }
}
async function removerAnexo(a) {
  if (!confirm(`Remover o comprovante "${a.nome_original}"?`)) return
  try {
    await http.delete(`/financeiro/anexos/${a.id}`)
    toast.success('Comprovante removido')
    alterou()
  } catch (e) { toast.error(e.mensagens?.[0]) }
}
const tamanho = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.ceil(b / 1024)} KB`)

const ACAO = {
  CRIADO: 'Criado', ALTERADO: 'Alterado', BAIXA: 'Baixa (pagamento)', ESTORNO: 'Estorno do pagamento',
  CANCELAMENTO: 'Cancelado', ANEXO_INCLUIDO: 'Comprovante anexado', ANEXO_REMOVIDO: 'Comprovante removido',
}
const CAMPO = {
  descricao: 'Descrição', valor: 'Valor', valor_pago: 'Valor pago', data_competencia: 'Competência', data_vencimento: 'Vencimento',
  data_pagamento: 'Pagamento', situacao: 'Situação', categoria_id: 'Categoria', lote_id: 'Lote', pessoa_id: 'Pessoa',
  conta_financeira_id: 'Conta', forma_pagamento: 'Forma', grupo_custo: 'Grupo de custo', documento: 'Documento',
  observacoes: 'Observações', motivo: 'Motivo', motivo_cancelamento: 'Motivo', anexo: 'Arquivo',
}
const fmt = (campo, v) => {
  if (v == null || v === '') return '—'
  if (String(campo).startsWith('valor')) return moeda(v)
  if (String(campo).startsWith('data_')) return dataBr(String(v))
  return String(v)
}
const mudancas = (h) => {
  const chaves = new Set([...Object.keys(h.antes ?? {}), ...Object.keys(h.depois ?? {})])
  return [...chaves].filter((k) => CAMPO[k] && !(h.acao === 'CRIADO')).map((k) => ({
    campo: CAMPO[k], antes: fmt(k, h.antes?.[k]), depois: fmt(k, h.depois?.[k]),
  }))
}
</script>

<template>
  <Sheet :open="!!id" @update:open="(v) => !v && emit('fechar')">
    <SheetContent class="w-full overflow-y-auto sm:max-w-lg">
      <SheetHeader>
        <SheetTitle>{{ l?.descricao ?? 'Lançamento' }}</SheetTitle>
        <SheetDescription v-if="l">{{ l.tipo_label }} · {{ l.categoria }}</SheetDescription>
      </SheetHeader>

      <div class="space-y-5 px-4 pb-6">
        <AlertaErros :mensagens="erros" />
        <template v-if="l">
          <div class="flex items-end justify-between gap-3">
            <div>
              <p class="text-xs text-muted-foreground">Valor</p>
              <p class="text-2xl font-semibold tabular-nums" :class="l.tipo === 'RECEITA' ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'">{{ moeda(l.valor) }}</p>
            </div>
            <SeloSituacao :situacao="l.situacao" :tipo="l.tipo" />
          </div>

          <div class="flex flex-wrap gap-2">
            <Button v-if="['PENDENTE', 'VENCIDO'].includes(l.situacao)" size="sm" @click="baixar = true">
              <CircleCheck /> {{ l.tipo === 'RECEITA' ? 'Receber' : 'Pagar' }}
            </Button>
            <Button v-if="l.situacao !== 'CANCELADO'" size="sm" variant="outline" @click="editar = true"><Pencil /> Editar</Button>
            <Button v-if="l.situacao === 'PAGO'" size="sm" variant="outline" @click="estornoAberto = true"><Undo2 /> Estornar</Button>
            <Button v-if="['PENDENTE', 'VENCIDO'].includes(l.situacao)" size="sm" variant="ghost" class="text-destructive" @click="cancelarAberto = true"><Ban /> Cancelar</Button>
          </div>

          <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div><dt class="text-xs text-muted-foreground">Competência</dt><dd>{{ dataBr(l.data_competencia) }}</dd></div>
            <div><dt class="text-xs text-muted-foreground">Vencimento</dt><dd>{{ dataBr(l.data_vencimento) }}</dd></div>
            <template v-if="l.situacao === 'PAGO'">
              <div><dt class="text-xs text-muted-foreground">{{ l.tipo === 'RECEITA' ? 'Recebido em' : 'Pago em' }}</dt><dd>{{ dataBr(l.data_pagamento) }}</dd></div>
              <div><dt class="text-xs text-muted-foreground">Valor pago</dt><dd class="tabular-nums">{{ moeda(l.valor_pago) }}</dd></div>
              <div><dt class="text-xs text-muted-foreground">Conta</dt><dd>{{ l.conta ?? '—' }}</dd></div>
              <div><dt class="text-xs text-muted-foreground">Forma</dt><dd>{{ l.forma_pagamento_label ?? '—' }}</dd></div>
            </template>
            <div><dt class="text-xs text-muted-foreground">{{ l.tipo === 'RECEITA' ? 'Cliente' : 'Fornecedor' }}</dt><dd>{{ l.pessoa ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted-foreground">Lote</dt><dd>{{ l.lote ?? 'Geral' }}</dd></div>
            <div v-if="l.grupo_custo_label"><dt class="text-xs text-muted-foreground">Grupo de custo</dt><dd>{{ l.grupo_custo_label }}</dd></div>
            <div v-if="l.total_parcelas"><dt class="text-xs text-muted-foreground">Parcela</dt><dd>{{ l.parcela }} de {{ l.total_parcelas }}</dd></div>
            <div v-if="l.documento"><dt class="text-xs text-muted-foreground">Documento</dt><dd>{{ l.documento }}</dd></div>
            <div v-if="l.observacoes" class="col-span-2"><dt class="text-xs text-muted-foreground">Observações</dt><dd class="whitespace-pre-line">{{ l.observacoes }}</dd></div>
            <div v-if="l.motivo_cancelamento" class="col-span-2"><dt class="text-xs text-muted-foreground">Motivo do cancelamento</dt><dd>{{ l.motivo_cancelamento }}</dd></div>
          </dl>

          <Separator />
          <section>
            <div class="mb-2 flex items-center justify-between">
              <h3 class="flex items-center gap-1.5 text-sm font-medium"><Paperclip class="size-4" /> Comprovantes</h3>
              <Button size="sm" variant="outline" :disabled="enviandoAnexo" @click="entrada.click()">{{ enviandoAnexo ? 'Enviando…' : 'Anexar' }}</Button>
              <input ref="entrada" type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.xml,.xls,.xlsx,.csv,.doc,.docx,.txt" @change="anexar" />
            </div>
            <ul v-if="l.anexos?.length" class="divide-y rounded-md border text-sm">
              <li v-for="a in l.anexos" :key="a.id" class="flex items-center gap-2 px-3 py-2">
                <span class="min-w-0 flex-1 truncate">{{ a.nome_original }} <span class="text-xs text-muted-foreground">· {{ tamanho(a.tamanho) }}</span></span>
                <Button size="icon" variant="ghost" class="size-7" aria-label="Baixar" @click="baixarAnexo(a)"><Download /></Button>
                <Button size="icon" variant="ghost" class="size-7 text-destructive" aria-label="Remover" @click="removerAnexo(a)"><Trash2 /></Button>
              </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">Nenhum comprovante. Anexe a nota, o boleto ou o recibo (PDF ou foto).</p>
          </section>

          <Separator />
          <section>
            <button class="flex items-center gap-1.5 text-sm font-medium" @click="verHistorico = !verHistorico">
              <History class="size-4" /> Histórico de alterações ({{ historico.length }})
            </button>
            <ol v-if="verHistorico" class="mt-3 space-y-3 border-l pl-4 text-sm">
              <li v-for="h in historico" :key="h.id">
                <p class="font-medium">{{ ACAO[h.acao] ?? h.acao }}</p>
                <p class="text-xs text-muted-foreground">{{ new Date(h.data).toLocaleString('pt-BR') }} · {{ h.usuario ?? 'sistema' }}</p>
                <ul v-if="mudancas(h).length" class="mt-1 space-y-0.5 text-xs">
                  <li v-for="m in mudancas(h)" :key="m.campo">
                    <span class="text-muted-foreground">{{ m.campo }}:</span>
                    <template v-if="h.antes && h.depois"> <s class="text-muted-foreground">{{ m.antes }}</s> → {{ m.depois }}</template>
                    <template v-else> {{ h.depois ? m.depois : m.antes }}</template>
                  </li>
                </ul>
              </li>
            </ol>
          </section>
        </template>
      </div>
    </SheetContent>
  </Sheet>

  <FormLancamento v-model:open="editar" :lancamento="l" @salvo="alterou" />
  <DialogoBaixa v-model:open="baixar" :lancamentos="l ? [l] : []" @baixado="alterou" />

  <Dialog v-model:open="estornoAberto">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Estornar pagamento</DialogTitle>
        <DialogDescription>O lançamento volta a ficar em aberto e o valor sai do saldo da conta. O pagamento desfeito fica no histórico.</DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />
      <div class="grid gap-1.5">
        <Label for="m-estorno">Motivo (opcional)</Label>
        <Input id="m-estorno" v-model="motivoEstorno" maxlength="255" placeholder="Ex.: lançado na conta errada" />
      </div>
      <DialogFooter>
        <Button variant="ghost" @click="estornoAberto = false">Voltar</Button>
        <Button :disabled="enviando" @click="estornar">Estornar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <Dialog v-model:open="cancelarAberto">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Cancelar lançamento</DialogTitle>
        <DialogDescription>Cancelados saem de todos os totais, mas continuam consultáveis no histórico.</DialogDescription>
      </DialogHeader>
      <AlertaErros :mensagens="mensagens" />
      <div class="grid gap-1.5">
        <Label for="m-canc">Motivo</Label>
        <Input id="m-canc" v-model="motivoCancelamento" maxlength="255" required placeholder="Ex.: lançado em duplicidade" />
      </div>
      <label v-if="l?.total_parcelas && l.parcela < l.total_parcelas" class="flex items-center gap-2 text-sm">
        <input v-model="parcelasSeguintes" type="checkbox" class="size-4 accent-primary" />
        Cancelar também as parcelas seguintes em aberto
      </label>
      <DialogFooter>
        <Button variant="ghost" @click="cancelarAberto = false">Voltar</Button>
        <Button variant="destructive" :disabled="enviando || !motivoCancelamento.trim()" @click="cancelar">Cancelar lançamento</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
