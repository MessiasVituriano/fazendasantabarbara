<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { ArrowLeft, Boxes, CalendarClock, HeartPulse, Mars, Milk, Scale, Syringe, Target, TrendingDown, TrendingUp, TriangleAlert, Venus } from '@lucide/vue'
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
import GraficoPeso from '../components/GraficoPeso.vue'
import LinhaDoTempo from '../components/LinhaDoTempo.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { useEnvio } from '../composables/useEnvio'
import { turnoAtual } from '../composables/usePeriodoAtual'
import { useDataMinima } from '../composables/useDataMinima'
import { useSessao } from '../composables/useSessao'
import { dataBr, hoje, idade, novoUuid, numero } from '../formatos'
import { avisarGuardadoOffline, enviarOuGuardar } from '../offline/fila'

const props = defineProps({ id: { type: String, required: true } })

const animal = ref(null)
const { ehGestor } = useSessao()
const dataMinima = useDataMinima()
const timeline = ref([])
const crescimento = ref(null)
const erroCarga = ref([])

async function carregar() {
  try {
    const [a, t, c] = await Promise.all([
      http.get(`/animais/${props.id}`),
      http.get(`/animais/${props.id}/timeline`),
      http.get(`/animais/${props.id}/crescimento`).catch(() => null), // sem conexão e sem cache: a ficha abre mesmo assim
    ])
    animal.value = a.data.data
    timeline.value = t.data.data
    crescimento.value = c?.data.data ?? null
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

// Crescimento: tendência das pesagens recentes, projeção e previsão para o peso alvo.
const tend = computed(() => crescimento.value?.tendencia)
const graficoPeso = computed(() => {
  const c = crescimento.value
  if (!c?.pesagens.length) return null
  const t = c.tendencia
  return {
    pontos: c.pesagens,
    reta: t.reta,
    projecao: t.projetavel ? [{ data: hoje(), peso: t.peso_estimado_hoje, margem: 0 }, ...t.projecoes] : [],
    alvo: { peso: c.alvo.peso_kg, data: c.meta?.data_alvo, rotulo: c.alvo.origem === 'META_LOTE' ? 'Meta do lote' : 'Peso de abate' },
  }
})
const TOM_TENDENCIA = { CONFIAVEL: 'text-emerald-700 dark:text-emerald-400', INSTAVEL: 'text-amber-700 dark:text-amber-400', IRREGULAR: 'text-destructive', INSUFICIENTE: 'text-muted-foreground' }

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
  const quem = descricaoAnimal.value
  const req = {
    pesagem: { metodo: 'post', url: '/pesagens', descricao: `Pesagem de ${dataBr(rapido.data)} · ${quem}`,
      dados: { uuid: novoUuid(), animal_id, data_pesagem: rapido.data, peso_kg: decimal(rapido.peso_kg) } },
    producao: { metodo: 'put', url: '/producoes-leite/turno', descricao: `Produção de leite de ${dataBr(rapido.data)} (${rapido.periodo === 'MANHA' ? 'manhã' : 'tarde'}) · ${quem}`,
      dados: { data_pesagem: rapido.data, periodo: rapido.periodo, registros: [{ animal_id, quantidade_litros: decimal(rapido.quantidade_litros) }] } },
  }
  try {
    const { offline } = await enviar(() => enviarOuGuardar(req[modal.value]))
    if (offline) avisarGuardadoOffline(TITULOS[modal.value])
    else toast.success(`${TITULOS[modal.value]} registrada`)
    modal.value = null
    if (!offline) carregar()
  } catch { /* erros no formulário */ }
}
const descricaoAnimal = computed(() => animal.value && [animal.value.brinco, animal.value.nome].filter(Boolean).join(' · '))
function eventoSalvo(evento) {
  modal.value = null
  if (!evento) return avisarGuardadoOffline('Evento reprodutivo')
  toast.success('Evento reprodutivo registrado')
  carregar()
}
function aplicacaoSalva(aplicacao) {
  modal.value = null
  if (!aplicacao) return avisarGuardadoOffline('Aplicação')
  toast.success('Aplicação registrada')
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

      <Card v-if="graficoPeso">
        <CardHeader class="flex flex-wrap items-start justify-between gap-2">
          <CardTitle class="flex items-center gap-2"><Scale class="size-4" /> Crescimento</CardTitle>
          <component :is="ehGestor ? 'RouterLink' : 'span'" v-if="crescimento.lote" :to="ehGestor ? `/lotes/${crescimento.lote.id}` : undefined"
                     class="flex items-center gap-1.5 text-sm text-muted-foreground" :class="{ 'hover:text-foreground': ehGestor }">
            <Boxes class="size-4" /> {{ crescimento.lote.nome }}
          </component>
        </CardHeader>
        <CardContent class="space-y-4">
          <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-lg border p-3">
              <dt class="text-xs text-muted-foreground">GMD de tendência</dt>
              <dd class="flex items-center gap-1.5 text-xl font-semibold tabular-nums">
                <template v-if="tend.gmd !== null">
                  <component :is="tend.gmd > 0 ? TrendingUp : TrendingDown" class="size-4" :class="tend.gmd > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive'" />
                  {{ numero(tend.gmd, 3) }} <span class="text-xs font-normal text-muted-foreground">kg/dia</span>
                </template>
                <template v-else>—</template>
              </dd>
              <dd class="text-xs" :class="TOM_TENDENCIA[tend.situacao]">
                {{ tend.situacao_label }}<template v-if="tend.r2 !== null"> · R² {{ numero(tend.r2, 2) }}</template>
              </dd>
            </div>
            <div class="rounded-lg border p-3">
              <dt class="text-xs text-muted-foreground">{{ tend.peso_estimado_hoje !== null ? 'Peso estimado hoje' : 'Última pesagem' }}</dt>
              <dd class="text-xl font-semibold tabular-nums">{{ numero(tend.peso_estimado_hoje ?? tend.ultima_pesagem?.peso, 1) }} <span class="text-xs font-normal text-muted-foreground">kg</span></dd>
              <dd class="text-xs text-muted-foreground">{{ tend.pesagens_usadas }} pesagem(ns) nos últimos 120 dias</dd>
            </div>
            <div class="rounded-lg border p-3">
              <dt class="text-xs text-muted-foreground">Em 90 dias</dt>
              <dd class="text-xl font-semibold tabular-nums">
                <template v-if="tend.projecoes.length">{{ numero(tend.projecoes.at(-1).peso, 0) }} <span class="text-xs font-normal text-muted-foreground">kg</span></template>
                <template v-else>—</template>
              </dd>
              <dd class="text-xs text-muted-foreground">
                <template v-if="tend.projecoes.at(-1)?.margem">± {{ numero(tend.projecoes.at(-1).margem, 0) }} kg</template>
                <template v-else-if="!tend.projetavel">Sem projeção: {{ tend.situacao_label.toLowerCase() }}</template>
              </dd>
            </div>
            <div class="rounded-lg border border-primary/30 bg-primary/5 p-3">
              <dt class="flex items-center gap-1 text-xs text-muted-foreground"><Target class="size-3.5" /> {{ crescimento.alvo.origem === 'META_LOTE' ? 'Meta do lote' : 'Peso de abate' }}: {{ numero(crescimento.alvo.peso_kg, 0) }} kg</dt>
              <dd class="text-xl font-semibold">
                <template v-if="crescimento.alvo.previsao?.dias === 0">Atingido</template>
                <template v-else-if="crescimento.alvo.previsao">{{ dataBr(crescimento.alvo.previsao.data) }}</template>
                <template v-else>—</template>
              </dd>
              <dd class="text-xs text-muted-foreground">
                <template v-if="crescimento.alvo.previsao?.dias">em {{ crescimento.alvo.previsao.dias }} dias no ritmo atual</template>
                <template v-else-if="!crescimento.alvo.previsao">Não atinge no ritmo atual</template>
              </dd>
            </div>
          </dl>
          <p v-if="tend.suspeitas.length" class="flex items-start gap-2 rounded-md bg-destructive/10 px-3 py-2 text-xs text-destructive">
            <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
            {{ tend.suspeitas.length }} pesagem(ns) fora da tendência ficaram fora da conta. Confira se o peso foi digitado certo.
          </p>
          <GraficoPeso v-bind="graficoPeso" />
        </CardContent>
      </Card>

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

      <FormEventoReprodutivo v-if="modal === 'evento'" :animal-id="animal.id" :descricao-animal="descricaoAnimal" @salvo="eventoSalvo" @cancelar="modal = null" />

      <FormAplicacaoSanitaria v-else-if="modal === 'vacina'" :animal-id="animal.id" :descricao-animal="descricaoAnimal" @salvo="aplicacaoSalva" @cancelar="modal = null" />

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
