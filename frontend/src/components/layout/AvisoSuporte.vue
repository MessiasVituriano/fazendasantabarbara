<script setup>
import { Headset } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import http from '@/api/http'
import { useSessao } from '@/composables/useSessao'

/** Faixa no topo enquanto o administrador da plataforma está numa fazenda de cliente como suporte. */
const { fazendaAtual, fazendas, atualizarFazendas, esquecerFazenda } = useSessao()
const ate = (iso) => new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })

async function encerrar() {
  const id = fazendaAtual.value.id
  try { await http.delete(`/admin/fazendas/${id}/suporte`) } catch { /* expira sozinho de qualquer forma */ }
  atualizarFazendas(fazendas.value.filter((f) => f.id !== id))
  esquecerFazenda()
  location.assign('/admin')
}
</script>

<template>
  <div v-if="fazendaAtual?.suporte" class="flex flex-wrap items-center gap-2 bg-amber-100 px-4 py-1.5 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200 md:px-6">
    <Headset class="size-4 shrink-0" />
    <span class="min-w-0 flex-1">Suporte na <b>{{ fazendaAtual.nome }}</b> até {{ ate(fazendaAtual.suporte_ate) }}. O que você alterar fica no histórico com o seu usuário.</span>
    <Button size="sm" variant="outline" class="h-7 border-amber-300 bg-transparent" @click="encerrar">Encerrar suporte</Button>
  </div>
</template>
