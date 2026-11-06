<script setup>
import { Badge } from '@/components/ui/badge'

/** Situação do lançamento: No dia (pendente a vencer), Atrasado (pendente vencido), Pago, Cancelado. */
defineProps({ situacao: { type: String, required: true }, tipo: { type: String, default: 'DESPESA' } })

const COR = {
  PENDENTE: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  VENCIDO: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
  PAGO: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  CANCELADO: 'bg-muted text-muted-foreground line-through',
}
const ROTULO = { PENDENTE: 'No dia', VENCIDO: 'Atrasado', CANCELADO: 'Cancelado' }
</script>

<template>
  <Badge class="border-transparent" :class="COR[situacao]">
    {{ situacao === 'PAGO' ? (tipo === 'RECEITA' ? 'Recebido' : 'Pago') : ROTULO[situacao] }}
  </Badge>
</template>
