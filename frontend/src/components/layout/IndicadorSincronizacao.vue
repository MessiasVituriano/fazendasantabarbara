<script setup>
import { computed, ref } from 'vue'
import { CloudAlert, CloudCheck, CloudOff, CloudUpload, RefreshCw, Trash2 } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { useSessao } from '@/composables/useSessao'
import { useFilaOffline } from '@/offline/fila'

/**
 * Situação da conexão e da fila offline. Some quando está tudo em dia;
 * aparece sem internet ou com registros guardados no aparelho.
 */
const { online, itens, pendentes, comErro, sincronizando, sincronizar, descartar, tentarDeNovo } = useFilaOffline()
const aberto = ref(false)
// Com mais de uma fazenda, cada registro mostra para qual fazenda vai.
const { fazendas } = useSessao()
const variasFazendas = computed(() => fazendas.value.length > 1)

const visivel = computed(() => !online.value || itens.value.length > 0)
const estado = computed(() => {
  if (comErro.value.length) return { icone: CloudAlert, cor: 'text-destructive', texto: `${comErro.value.length} com erro` }
  if (!online.value) return { icone: CloudOff, cor: 'text-amber-600 dark:text-amber-400', texto: pendentes.value.length ? `Offline · ${pendentes.value.length}` : 'Offline' }
  if (pendentes.value.length) return { icone: CloudUpload, cor: 'text-amber-600 dark:text-amber-400', texto: `${pendentes.value.length} a enviar` }
  return { icone: CloudCheck, cor: 'text-emerald-600', texto: 'Em dia' }
})
const quando = (iso) => new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
function confirmarDescarte(item) {
  if (confirm(`Descartar "${item.descricao}"? O registro não será enviado.`)) descartar(item.id)
}
</script>

<template>
  <template v-if="visivel">
    <Tooltip>
      <TooltipTrigger as-child>
        <Button variant="ghost" class="h-9 gap-1.5 px-2" :class="estado.cor" aria-label="Sincronização offline" @click="aberto = true">
          <component :is="estado.icone" :class="{ 'animate-pulse': sincronizando }" />
          <span class="text-xs font-medium tabular-nums">{{ estado.texto }}</span>
        </Button>
      </TooltipTrigger>
      <TooltipContent>{{ online ? 'Registros guardados neste aparelho' : 'Sem internet: os registros ficam guardados no aparelho' }}</TooltipContent>
    </Tooltip>

    <Dialog v-model:open="aberto">
      <DialogContent class="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Registros offline</DialogTitle>
          <DialogDescription>
            <template v-if="online">Conectado. Os registros abaixo foram feitos sem internet e são enviados automaticamente.</template>
            <template v-else>Sem internet. Continue registrando: tudo fica guardado neste aparelho e sobe sozinho quando a conexão voltar.</template>
          </DialogDescription>
        </DialogHeader>

        <ul v-if="itens.length" class="max-h-80 divide-y overflow-y-auto rounded-md border">
          <li v-for="item in itens" :key="item.id" class="flex items-start gap-3 px-3 py-2.5">
            <component :is="item.estado === 'erro' ? CloudAlert : CloudUpload" class="mt-0.5 size-4 shrink-0"
                       :class="item.estado === 'erro' ? 'text-destructive' : 'text-amber-600 dark:text-amber-400'" />
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium">{{ item.descricao }}</p>
              <p class="text-xs text-muted-foreground">
                <template v-if="variasFazendas && item.fazendaNome">{{ item.fazendaNome }} · </template>Feito em {{ quando(item.criadoEm) }} · {{ item.estado === 'erro' ? 'recusado pelo servidor' : 'aguardando envio' }}
              </p>
              <p v-for="(m, i) in item.erros" :key="i" class="mt-1 text-xs text-destructive">{{ m }}</p>
            </div>
            <Button v-if="item.estado === 'erro'" variant="ghost" size="icon" class="size-8" aria-label="Tentar de novo" :disabled="!online || sincronizando" @click="tentarDeNovo(item.id)"><RefreshCw /></Button>
            <Button variant="ghost" size="icon" class="size-8 text-muted-foreground" aria-label="Descartar" @click="confirmarDescarte(item)"><Trash2 /></Button>
          </li>
        </ul>
        <p v-else class="py-6 text-center text-sm text-muted-foreground">Nenhum registro aguardando envio.</p>

        <div class="flex justify-end">
          <Button :disabled="!online || sincronizando || !pendentes.length" @click="sincronizar()">
            <RefreshCw :class="{ 'animate-spin': sincronizando }" /> {{ sincronizando ? 'Sincronizando…' : 'Sincronizar agora' }}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  </template>
</template>
