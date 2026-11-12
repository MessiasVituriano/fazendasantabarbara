<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Check, Nfc, TriangleAlert, Zap } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useSessao } from '../composables/useSessao'
import LinkCompartilhavel from './LinkCompartilhavel.vue'

/**
 * Grava na tag NFC do animal o link do registro rápido (`/registro-rapido/:id?fazenda=N`).
 * Para LER, nada é preciso: Android e iPhone abrem o link sozinhos ao encostar o celular na tag,
 * e a tela abre com o animal pronto para pesar ou registrar o leite.
 * Para GRAVAR pelo navegador, só o Chrome no Android (Web NFC, com HTTPS); nos outros aparelhos,
 * o link é copiado e gravado com um app de NFC (registro do tipo URL).
 */
const props = defineProps({ animal: { type: Object, required: true } })
const aberto = defineModel('open', { type: Boolean, default: false })

const { fazendaId } = useSessao()
const link = computed(() => `${location.origin}/registro-rapido/${props.animal.id}${fazendaId.value ? `?fazenda=${fazendaId.value}` : ''}`)
const gravaPeloNavegador = typeof window !== 'undefined' && 'NDEFReader' in window

const estado = ref('parado') // parado | aguardando | gravada
const erro = ref('')
let controle = null
let tempo = null

function parar() {
  clearTimeout(tempo)
  controle?.abort()
  controle = null
}

const MENSAGENS = {
  NotAllowedError: 'Permissão de NFC negada. Libere o NFC para este site nas configurações do Chrome e tente de novo.',
  NotSupportedError: 'O NFC está desligado ou este aparelho não tem NFC. Ligue o NFC nas configurações do celular.',
  NetworkError: 'A tag se afastou antes de terminar. Segure o celular parado sobre a tag e tente de novo.',
  NotReadableError: 'Não foi possível usar o NFC agora. Feche outros apps de NFC e tente de novo.',
}

async function gravar() {
  parar()
  erro.value = ''
  estado.value = 'aguardando'
  controle = new AbortController()
  const sinal = controle.signal
  tempo = setTimeout(() => controle?.abort(), 30000)
  try {
    const ndef = new window.NDEFReader()
    await ndef.write({ records: [{ recordType: 'url', data: link.value }] }, { signal: sinal, overwrite: true })
    estado.value = 'gravada'
    try { navigator.vibrate?.(70) } catch { /* sem vibração */ }
    toast.success(`Tag gravada para ${props.animal.brinco}`)
  } catch (e) {
    estado.value = 'parado'
    if (e.name === 'AbortError') erro.value = sinal.aborted && aberto.value ? 'Nenhuma tag encostada em 30 segundos. Tente de novo.' : ''
    else erro.value = MENSAGENS[e.name] ?? `Não foi possível gravar a tag (${e.message || e.name}).`
  } finally {
    clearTimeout(tempo)
  }
}

watch(aberto, (v) => {
  if (v) { estado.value = 'parado'; erro.value = '' } else parar()
})
onBeforeUnmount(parar)
</script>

<template>
  <Dialog v-model:open="aberto">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2"><Nfc class="size-5" /> Tag NFC de {{ animal.brinco }}</DialogTitle>
        <DialogDescription>
          Ao encostar o celular na tag, abre o registro rápido deste animal, pronto para pesar ou lançar o leite.
          Funciona no Android e no iPhone, sem app.
        </DialogDescription>
      </DialogHeader>

      <div class="grid gap-4">
        <template v-if="gravaPeloNavegador">
          <Button size="lg" class="h-14 text-base" :disabled="estado === 'aguardando'" @click="gravar">
            <Nfc /> {{ estado === 'gravada' ? 'Gravar outra tag' : 'Gravar na tag' }}
          </Button>
          <p v-if="estado === 'aguardando'" class="animate-pulse text-center text-sm font-medium">
            Encoste a tag na parte de trás do celular e segure parado…
          </p>
          <p v-else-if="estado === 'gravada'" class="flex items-center justify-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-400">
            <Check class="size-4" /> Tag gravada. Encoste de novo para testar.
          </p>
          <Alert v-if="erro" variant="destructive"><TriangleAlert /><AlertDescription>{{ erro }}</AlertDescription></Alert>
        </template>
        <Alert v-else>
          <TriangleAlert />
          <AlertDescription>
            Este aparelho não grava tags pelo navegador (só o Chrome no Android grava). Copie o link abaixo e grave com um app
            de NFC, como o NFC Tools: Escrever → Adicionar registro → URL.
          </AlertDescription>
        </Alert>

        <LinkCompartilhavel :link="link" aviso="Use tags NTAG213, NTAG215 ou NTAG216. Este é o link gravado na tag." />

        <Button as-child variant="outline">
          <RouterLink :to="`/registro-rapido/${animal.id}`"><Zap /> Abrir o registro rápido</RouterLink>
        </Button>
      </div>
    </DialogContent>
  </Dialog>
</template>
