<script setup>
import { ref, watch } from 'vue'
import { Camera, Star, Trash2 } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import http from '../api/http'
import { useSessao } from '../composables/useSessao'
import { comprimirImagem, dataBr } from '../formatos'

/** Galeria de fotos do animal: envio (câmera ou arquivos), foto principal, legenda e exclusão. */
const props = defineProps({ animalId: { type: Number, required: true } })
const emit = defineEmits(['alterada'])

const { ehGestor } = useSessao()
const fotos = ref([])
const enviando = ref(0)
const ampliada = ref(null)
const seletor = ref(null)

async function carregar() {
  fotos.value = (await http.get(`/animais/${props.animalId}/fotos`)).data.data
}
watch(() => props.animalId, carregar, { immediate: true })

async function enviar(evento) {
  const arquivos = [...evento.target.files]
  evento.target.value = ''
  enviando.value = arquivos.length
  for (const arquivo of arquivos) {
    try {
      const dados = new FormData()
      dados.append('foto', await comprimirImagem(arquivo))
      await http.post(`/animais/${props.animalId}/fotos`, dados)
    } catch (e) {
      toast.error(`Falha ao enviar ${arquivo.name}`, { description: e.mensagens?.[0] ?? 'Não foi possível ler a imagem.' })
    } finally {
      enviando.value--
    }
  }
  await carregar()
  emit('alterada')
}

async function acao(fn, sucesso) {
  try {
    await fn()
    await carregar()
    emit('alterada')
    if (sucesso) toast.success(sucesso)
  } catch (e) {
    toast.error(e.mensagens?.[0] ?? 'Não foi possível concluir.')
  }
}
const tornarPrincipal = (foto) => acao(async () => {
  await http.patch(`/animais/${props.animalId}/fotos/${foto.id}`, { principal: true })
  foto.principal = true
}, 'Foto principal atualizada')
const excluir = (foto) => confirm('Excluir esta foto?') && acao(async () => {
  await http.delete(`/animais/${props.animalId}/fotos/${foto.id}`)
  ampliada.value = null
}, 'Foto excluída')
const salvarLegenda = (foto) => acao(() => http.patch(`/animais/${props.animalId}/fotos/${foto.id}`, { legenda: foto.legenda || null }))
</script>

<template>
  <div>
    <div class="mb-3 flex items-center justify-between">
      <h2 class="font-semibold">Fotos <span class="text-sm font-normal text-muted-foreground">({{ fotos.length }})</span></h2>
      <Button variant="outline" size="sm" :disabled="enviando > 0" @click="seletor.click()">
        <Camera /> {{ enviando ? `Enviando ${enviando}…` : 'Adicionar' }}
      </Button>
      <input ref="seletor" type="file" accept="image/*" multiple class="hidden" @change="enviar" />
    </div>

    <p v-if="!fotos.length && !enviando" class="text-sm text-muted-foreground">
      Nenhuma foto. A primeira foto enviada passa a ser o avatar do animal.
    </p>
    <div v-else class="grid grid-cols-3 gap-2">
      <button
        v-for="f in fotos" :key="f.id"
        class="relative aspect-square overflow-hidden rounded-md bg-muted outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        :title="f.legenda || dataBr(f.data_registro)" @click="ampliada = f"
      >
        <img :src="f.miniatura_url" :alt="f.legenda || 'Foto do animal'" loading="lazy" class="size-full object-cover" />
        <Badge v-if="f.principal" class="absolute top-1 left-1 px-1.5 text-[10px]">Principal</Badge>
      </button>
    </div>

    <Dialog :open="!!ampliada" @update:open="(v) => !v && (ampliada = null)">
      <DialogContent v-if="ampliada" class="sm:max-w-3xl">
        <DialogTitle class="sr-only">Foto do animal</DialogTitle>
        <DialogDescription class="sr-only">Visualização ampliada</DialogDescription>
        <img :src="ampliada.url" :alt="ampliada.legenda || 'Foto do animal'" class="mx-auto max-h-[65vh] rounded-md object-contain" />
        <div class="flex flex-wrap items-center gap-2">
          <Input v-model="ampliada.legenda" :disabled="!ehGestor" class="min-w-48 flex-1" placeholder="Legenda (opcional)" maxlength="255"
                 @keyup.enter="salvarLegenda(ampliada)" @blur="salvarLegenda(ampliada)" />
          <span class="text-xs text-muted-foreground">{{ dataBr(ampliada.data_registro) }}</span>
          <Button v-if="ehGestor && !ampliada.principal" variant="outline" @click="tornarPrincipal(ampliada)"><Star /> Tornar principal</Button>
          <Button v-if="ehGestor" variant="destructive" @click="excluir(ampliada)"><Trash2 /> Excluir</Button>
        </div>
      </DialogContent>
    </Dialog>
  </div>
</template>
