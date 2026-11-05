<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { ArrowLeft, CalendarClock, HeartPulse, Mars, Milk, Scale, Syringe, TriangleAlert, Venus } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import http from '../api/http'
import AlertaErros from '../components/AlertaErros.vue'
import AvatarAnimal from '../components/AvatarAnimal.vue'
import FormAplicacaoSanitaria from '../components/FormAplicacaoSanitaria.vue'
import FormEventoReprodutivo from '../components/FormEventoReprodutivo.vue'
import GaleriaFotos from '../components/GaleriaFotos.vue'
import LinhaDoTempo from '../components/LinhaDoTempo.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { useEnvio } from '../composables/useEnvio'
import { turnoAtual } from '../composables/usePeriodoAtual'
import { useDataMinima } from '../composables/useDataMinima'
import { dataBr, hoje, idade, novoUuid } from '../formatos'

const props = defineProps({ id: { type: String, required: true } })

const animal = ref(null)
const dataMinima = useDataMinima()
const timeline = ref([])
const erroCarga = ref([])

async function carregar() {
  try {
    const [a, t] = await Promise.all([http.get(`/animais/${props.id}`), http.get(`/animais/${props.id}/timeline`)])
    animal.value = a.data.data
    timeline.value = t.data.data
    erroCarga.value = []
  } catch (e) {
    erroCarga.value = e.mensagens
  }
}
watch(() => props.id, carregar, { immediate: true })

const femea = computed(() => animal.value?.sexo === 'F')
const dados = computed(() => animal.value && [
  ['Idade', idade(animal.value.data_nascimento)],
  ['Nascimento', dataBr(animal.value.data_nascimento)],
  ['Aptidão', animal.value.aptidao_label],
  ['Categoria', animal.value.categoria_label],
  ['Status geral', animal.value.status_geral_label],
  ...(femea.value ? [['Status reprodutivo', animal.value.status_reprodutivo_label]] : []),
  ['Mãe', animal.value.mae?.brinco ?? '—'],
  ['Piquete atual', animal.value.piquete_atual ? `${animal.value.piquete_atual.identificacao} desde ${dataBr(animal.value.piquete_atual.desde)}` : '—'],
])
const projecoes = computed(() => animal.value && [
  ['Toque previsto', animal.value.data_prevista_toque],
  ['Secagem prevista', animal.value.data_prevista_secagem],
  ['Parto provável', animal.value.data_provavel_parto],
].filter(([, v]) => v))

// Lançamentos rápidos
const modal = ref(null)
const TITULOS = { evento: 'Novo evento reprodutivo', pesagem: 'Nova pesagem', producao: 'Produção de leite', vacina: 'Vacina ou medicamento' }
const rapido = reactive({ data: hoje(), peso_kg: '', periodo: turnoAtual(), quantidade_litros: '' })
const { enviando, mensagens, campos, enviar } = useEnvio()
const erro = (campo) => campos.value[`registros.0.${campo}`] ?? campos.value[campo]

function abrir(tipo) {
  mensagens.value = []
  campos.value = {}
  Object.assign(rapido, { data: hoje(), periodo: turnoAtual() })
  modal.value = tipo
}
async function salvarRapido() {
  const animal_id = animal.value.id
  const decimal = (v) => String(v).replace(',', '.')
  const req = {
    pesagem: () => http.post('/pesagens', { uuid: novoUuid(), animal_id, data_pesagem: rapido.data, peso_kg: decimal(rapido.peso_kg) }),
    producao: () => http.put('/producoes-leite/turno', { data_pesagem: rapido.data, periodo: rapido.periodo, registros: [{ animal_id, quantidade_litros: decimal(rapido.quantidade_litros) }] }),
  }
  try {
    await enviar(req[modal.value])
    toast.success(`${TITULOS[modal.value]} registrada`)
    modal.value = null
    carregar()
  } catch { /* erros no formulário */ }
}
function eventoSalvo() {
  toast.success('Evento reprodutivo registrado')
  modal.value = null
  carregar()
}
function aplicacaoSalva() {
  toast.success('Aplicação registrada')
  modal.value = null
  carregar()
}
</script>

<template>
  <CabecalhoPagina :titulo="animal ? (animal.nome || animal.brinco) : 'Ficha do animal'">
    <template #antes-titulo>
      <Button as-child variant="ghost" size="icon" class="-ml-2"><RouterLink to="/animais" aria-label="Voltar ao rebanho"><ArrowLeft /></RouterLink></Button>
    </template>
    <template v-if="animal" #acoes>
      <Button v-if="femea" @click="abrir('evento')"><HeartPulse /> Evento</Button>
      <Button variant="outline" @click="abrir('pesagem')"><Scale /> Pesagem</Button>
      <Button v-if="femea" variant="outline" @click="abrir('producao')"><Milk /> Produção</Button>
      <Button variant="outline" @click="abrir('vacina')"><Syringe /> Vacina/medicamento</Button>
    </template>
  </CabecalhoPagina>

  <main class="space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erroCarga" />
    <template v-if="animal">
      <div class="flex items-center gap-4">
        <AvatarAnimal :animal="animal" :tamanho="80" />
        <div class="space-y-1.5">
          <p class="flex items-center gap-2 text-2xl font-semibold tracking-tight">
            {{ animal.nome || animal.brinco }}
            <component :is="femea ? Venus : Mars" class="size-5" :class="femea ? 'text-pink-500' : 'text-sky-500'" />
          </p>
          <div class="flex flex-wrap gap-1.5">
            <Badge variant="outline" class="font-mono">{{ animal.brinco }}</Badge>
            <Badge variant="secondary">{{ animal.categoria_label }}</Badge>
            <Badge variant="secondary">{{ idade(animal.data_nascimento) }}</Badge>
          </div>
        </div>
      </div>

      <Alert v-if="animal.alerta" variant="destructive">
        <TriangleAlert />
        <AlertTitle>Alerta crítico de carência</AlertTitle>
        <AlertDescription>{{ animal.alerta.mensagem }}</AlertDescription>
      </Alert>

      <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4">
          <Card>
            <CardHeader><CardTitle>Ficha</CardTitle></CardHeader>
            <CardContent>
              <dl class="space-y-2 text-sm">
                <div v-for="[rotulo, valor] in dados" :key="rotulo" class="flex justify-between gap-4">
                  <dt class="text-muted-foreground">{{ rotulo }}</dt><dd class="text-right font-medium">{{ valor }}</dd>
                </div>
              </dl>
            </CardContent>
          </Card>
          <Card v-if="projecoes.length" class="border-primary/30 bg-primary/5">
            <CardHeader><CardTitle class="flex items-center gap-2"><CalendarClock class="size-4" /> Projeções reprodutivas</CardTitle></CardHeader>
            <CardContent>
              <dl class="space-y-2 text-sm">
                <div v-for="[rotulo, valor] in projecoes" :key="rotulo" class="flex justify-between">
                  <dt class="text-muted-foreground">{{ rotulo }}</dt><dd class="font-semibold">{{ dataBr(valor) }}</dd>
                </div>
              </dl>
            </CardContent>
          </Card>
          <Card><CardContent><GaleriaFotos :animal-id="animal.id" @alterada="carregar" /></CardContent></Card>
        </div>

        <Card class="lg:col-span-2">
          <CardHeader><CardTitle>Linha do tempo</CardTitle></CardHeader>
          <CardContent><LinhaDoTempo :itens="timeline" /></CardContent>
        </Card>
      </div>
    </template>
  </main>

  <Dialog :open="!!modal" @update:open="(v) => !v && (modal = null)">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ TITULOS[modal] }}</DialogTitle>
        <DialogDescription v-if="animal">{{ animal.nome || animal.brinco }} · {{ animal.brinco }}</DialogDescription>
      </DialogHeader>

      <FormEventoReprodutivo v-if="modal === 'evento'" :animal-id="animal.id" @salvo="eventoSalvo" @cancelar="modal = null" />

      <FormAplicacaoSanitaria v-else-if="modal === 'vacina'" :animal-id="animal.id" @salvo="aplicacaoSalva" @cancelar="modal = null" />

      <form v-else id="form-rapido" class="grid gap-4" @submit.prevent="salvarRapido">
        <AlertaErros :mensagens="Object.keys(campos).length ? [] : mensagens" />
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="grid gap-1.5">
            <Label for="data-rapido">Data</Label>
            <Input id="data-rapido" v-model="rapido.data" type="date" :min="dataMinima" :max="hoje()" required />
          </div>
          <div v-if="modal === 'producao'" class="grid gap-1.5">
            <Label>Turno</Label>
            <ToggleGroup v-model="rapido.periodo" type="single" variant="outline" class="w-full">
              <ToggleGroupItem value="MANHA" class="flex-1 data-[state=on]:bg-primary data-[state=on]:text-primary-foreground data-[state=on]:hover:bg-primary/90">Manhã</ToggleGroupItem>
              <ToggleGroupItem value="TARDE" class="flex-1 data-[state=on]:bg-primary data-[state=on]:text-primary-foreground data-[state=on]:hover:bg-primary/90">Tarde</ToggleGroupItem>
            </ToggleGroup>
          </div>
          <div v-if="modal === 'pesagem'" class="grid gap-1.5">
            <Label for="peso">Peso (kg)</Label>
            <Input id="peso" v-model="rapido.peso_kg" inputmode="decimal" required :aria-invalid="!!erro('peso_kg')" />
            <p v-if="erro('peso_kg')" class="text-xs text-destructive">{{ erro('peso_kg') }}</p>
          </div>
          <div v-if="modal === 'producao'" class="grid gap-1.5 sm:col-span-2">
            <Label for="litros">Litros</Label>
            <Input id="litros" v-model="rapido.quantidade_litros" inputmode="decimal" required :aria-invalid="!!erro('quantidade_litros')" />
            <p class="text-xs text-muted-foreground">Se já houver lançamento neste turno, o valor é corrigido.</p>
          </div>
        </div>
      </form>

      <DialogFooter v-if="modal !== 'evento' && modal !== 'vacina'">
        <Button variant="ghost" @click="modal = null">Cancelar</Button>
        <Button type="submit" form="form-rapido" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
