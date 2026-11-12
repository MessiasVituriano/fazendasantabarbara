<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useEventListener, useWakeLock } from '@vueuse/core'
import { ArrowLeft, Check, CloudUpload, Delete, Search, SkipForward, Sun, Sunset, TriangleAlert } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import http from '../api/http'
import AvatarAnimal from '../components/AvatarAnimal.vue'
import IndicadorSincronizacao from '../components/layout/IndicadorSincronizacao.vue'
import { useOpcoes } from '../composables/useOpcoes'
import { turnoAtual } from '../composables/usePeriodoAtual'
import { dataBr, hoje, novoUuid, numero } from '../formatos'
import { avisarGuardadoOffline, useFilaOffline } from '../offline/fila'

/**
 * Registro rápido no curral: peso ou leite de um animal por vez, com teclado numérico grande.
 *
 * - `/registro-rapido/:id` abre o animal pronto para digitar. É o link gravado na tag NFC do animal
 *   (com `?fazenda=`; o router troca de fazenda se preciso). Sem `?modo=`, vaca em lactação abre em
 *   LEITE e os demais em PESO.
 * - `/registro-rapido` (ou `?fila=1`) percorre a fila: animais de corte/mista no peso, vacas em lactação
 *   no leite. Depois de salvar, vai para o próximo ainda sem registro hoje (ou neste turno).
 * - Animal aberto pela tag ou pela busca fica na tela depois de salvar (o próximo vem pela tag dele).
 * - Peso: uma pesagem por animal por dia (regra do banco); animal já pesado hoje fica bloqueado.
 * - Leite grava sempre hoje, no turno do relógio (troca Manhã/Tarde na tela); se a vaca já tem valor
 *   no turno, o novo substitui (mesma regra da Ordenha).
 * - Sem internet, o registro vai para a fila offline e sobe sozinho depois.
 */
const props = defineProps({ id: { type: String, default: undefined } })
const route = useRoute()
const router = useRouter()
const { opcoes } = useOpcoes()
const { pendentes, enviarOuGuardar } = useFilaOffline()

const MODOS = {
  peso: { rotulo: 'PESO (KG)', unidade: 'kg', inteiros: 4, maximo: 9999.99, casas: 1, nome: 'Peso' },
  leite: { rotulo: 'LEITE (L)', unidade: 'L', inteiros: 3, maximo: 999.99, casas: 1, nome: 'Leite' },
}
const TURNOS = [{ value: 'MANHA', label: 'Manhã', icone: Sun }, { value: 'TARDE', label: 'Tarde', icone: Sunset }]
const nomeTurno = (p) => (p === 'TARDE' ? 'tarde' : 'manhã')

const modoDaUrl = () => (['peso', 'leite'].includes(route.query.modo) ? route.query.modo : null)
const modo = ref(modoDaUrl() ?? 'peso')
const periodo = ref(turnoAtual())
const emFila = computed(() => !!route.query.fila || !props.id)

// Toque leve em cada tecla e forte ao salvar (Android; o iPhone não vibra pelo navegador).
const vibrar = (padrao) => { try { navigator.vibrate?.(padrao) } catch { /* sem vibração */ } }
const VIBRA_TECLA = 10
const VIBRA_SALVOU = 70
const VIBRA_ERRO = [40, 60, 40]

// Tela acesa enquanto esta tela estiver aberta (volta a pedir ao retornar para a aba).
const { isSupported: telaAcesaSuportada, request: manterTelaAcesa } = useWakeLock()
onMounted(() => { if (telaAcesaSuportada.value) manterTelaAcesa('screen').catch(() => {}) })

// ---------------------------------------------------------------- dados
const dadosDoAparelho = ref(false)
/** GET com a cópia do aparelho quando não há internet; `null` = sem rede e sem cópia. */
async function ler(url, params) {
  try {
    const r = await http.get(url, { params })
    if (r.offline) dadosDoAparelho.value = true
    return r.data.data
  } catch (e) {
    if (e.response) throw e
    dadosDoAparelho.value = true
    return null
  }
}

// Os parâmetros são os mesmos do pré-carregamento (offline/aquecer.js), para abrir sem internet.
const rebanho = ref([])      // busca e consulta offline do animal
const filaPeso = ref([])     // corte e mista
const filaLeite = ref([])    // vacas em lactação
const pesadosHoje = ref({})  // animal_id -> kg (servidor)
const leiteDoTurno = ref({}) // animal_id -> litros (servidor, hoje + turno)

let carregandoListas = null
function carregarListas() {
  carregandoListas ??= Promise.allSettled([
    ler('/animais', { por_pagina: 200 }).then((d) => (rebanho.value = d ?? [])),
    ler('/animais', { aptidao: ['CORTE', 'MISTA'], por_pagina: 200 }).then((d) => (filaPeso.value = d ?? [])),
    ler('/manejo/ordenha').then((d) => (filaLeite.value = d ?? [])),
    ler('/pesagens', { por_pagina: 500 }).then((d) => {
      const dia = hoje()
      pesadosHoje.value = Object.fromEntries((d ?? []).filter((p) => p.data_pesagem?.slice(0, 10) === dia).map((p) => [p.animal_id, Number(p.peso_kg)]))
    }),
    carregarTurno(),
  ])
  return carregandoListas
}
async function carregarTurno() {
  const dia = hoje()
  const d = await ler('/producoes-leite', { data_inicio: dia, data_fim: dia, periodo: periodo.value, por_pagina: 500 }).catch(() => null)
  leiteDoTurno.value = Object.fromEntries((d ?? []).map((p) => [p.animal_id, Number(p.quantidade_litros)]))
}
watch(periodo, carregarTurno)

// Registros feitos neste aparelho que ainda não subiram (contam como feitos e aparecem no histórico).
const registrosNaFila = computed(() => {
  const lista = []
  for (const item of pendentes.value) {
    if (item.url === '/pesagens') {
      for (const r of [].concat(item.dados)) lista.push({ tipo: 'peso', animal_id: r.animal_id, data: r.data_pesagem, valor: Number(r.peso_kg) })
    } else if (item.url === '/producoes-leite/turno') {
      for (const r of item.dados.registros) {
        lista.push({ tipo: 'leite', animal_id: r.animal_id, data: item.dados.data_pesagem, periodo: item.dados.periodo, valor: r.quantidade_litros == null ? null : Number(r.quantidade_litros) })
      }
    }
  }
  return lista
})

/** animal_id -> valor já registrado hoje (peso) ou neste turno (leite), juntando servidor e fila do aparelho. */
const feitos = computed(() => {
  const dia = hoje()
  const mapa = { ...(modo.value === 'peso' ? pesadosHoje.value : leiteDoTurno.value) }
  for (const r of registrosNaFila.value) {
    if (r.tipo !== modo.value || r.data !== dia || (r.tipo === 'leite' && r.periodo !== periodo.value)) continue
    if (r.valor === null) delete mapa[r.animal_id]
    else mapa[r.animal_id] = r.valor
  }
  return mapa
})

// ---------------------------------------------------------------- animal atual
const animal = ref(null)
const carregandoAnimal = ref(false)
const erroAnimal = ref('')
const podeLeite = computed(() => !animal.value || animal.value.sexo === 'F')

async function carregarAnimal(id) {
  carregandoAnimal.value = true
  erroAnimal.value = ''
  try {
    const r = await http.get(`/animais/${id}`)
    if (r.offline) dadosDoAparelho.value = true
    animal.value = r.data.data
  } catch (e) {
    animal.value = null
    if (e.response?.status === 404) erroAnimal.value = 'Animal não encontrado nesta fazenda.'
    else if (!e.response) {
      // Sem internet e sem a ficha guardada: procura nas listas pré-carregadas.
      await carregarListas()
      const n = Number(id)
      animal.value = [...rebanho.value, ...filaPeso.value, ...filaLeite.value].find((a) => a.id === n) ?? null
      if (!animal.value) erroAnimal.value = 'Sem internet e sem os dados deste animal no aparelho.'
    } else erroAnimal.value = e.mensagens?.[0] ?? 'Não foi possível abrir o animal.'
  } finally {
    carregandoAnimal.value = false
  }
  if (!animal.value) return
  if (!modoDaUrl()) modo.value = animal.value.categoria === 'LACTACAO' ? 'leite' : 'peso'
  if (modo.value === 'leite' && !podeLeite.value) modo.value = 'peso'
}

// ---------------------------------------------------------------- fila
const fila = computed(() => (modo.value === 'peso' ? filaPeso.value : filaLeite.value))
const faltamNaFila = computed(() => fila.value.filter((a) => !(a.id in feitos.value)).length)

/** Próximo da fila ainda sem registro, a partir do atual (dá a volta no fim). */
function proximoDaFila() {
  const i = fila.value.findIndex((a) => a.id === animal.value?.id)
  const ordem = [...fila.value.slice(i + 1), ...fila.value.slice(0, Math.max(i, 0))]
  return ordem.find((a) => !(a.id in feitos.value)) ?? null
}

function irPara(alvo, { daFila }) {
  const query = { modo: modo.value, ...(daFila ? { fila: '1' } : {}) }
  router.replace({ name: 'registro-rapido', params: { id: String(alvo.id) }, query })
}

async function pular() {
  await carregarListas()
  const prox = proximoDaFila()
  if (prox) irPara(prox, { daFila: true })
  else toast.info(modo.value === 'peso' ? 'Todos os animais de corte já foram pesados hoje.' : 'Todas as vacas em lactação já têm leite neste turno.')
}

function trocarModo(novo) {
  if (novo === modo.value || (novo === 'leite' && !podeLeite.value)) return
  vibrar(VIBRA_TECLA)
  modo.value = novo
  valor.value = ''
  router.replace({ query: { ...route.query, modo: novo } })
}

// ---------------------------------------------------------------- histórico (últimos 3)
const historicoServidor = ref([])
let pedidoHistorico = 0
async function carregarHistorico() {
  const a = animal.value
  historicoServidor.value = []
  if (!a) return
  const pedido = ++pedidoHistorico
  const peso = modo.value === 'peso'
  let lista = await ler(peso ? '/pesagens' : '/producoes-leite', { animal_id: a.id, por_pagina: 3 }).catch(() => [])
  if (lista === null && peso) lista = (await ler('/pesagens', { por_pagina: 500 }).catch(() => null))?.filter((p) => p.animal_id === a.id) ?? []
  if (pedido !== pedidoHistorico) return
  historicoServidor.value = (lista ?? []).map((p) => ({
    data: p.data_pesagem.slice(0, 10), periodo: p.periodo ?? null, valor: Number(peso ? p.peso_kg : p.quantidade_litros), pendente: false,
  }))
}
const ordemHistorico = (h) => `${h.data}${h.periodo === 'TARDE' ? 2 : 1}`
const historico = computed(() => {
  const id = animal.value?.id
  const naFila = registrosNaFila.value.filter((r) => r.tipo === modo.value && r.animal_id === id).map((r) => ({ ...r, pendente: true }))
  // No leite, o registro guardado no aparelho substitui o do servidor no mesmo dia e turno.
  const chavesNaFila = new Set(naFila.map(ordemHistorico))
  const doServidor = historicoServidor.value.filter((h) => modo.value === 'peso' || !chavesNaFila.has(ordemHistorico(h)))
  return [...naFila.filter((r) => r.valor !== null), ...doServidor]
    .sort((x, y) => ordemHistorico(y).localeCompare(ordemHistorico(x)))
    .slice(0, 3)
})
const dataCurta = (iso) => (iso === hoje() ? 'Hoje' : dataBr(iso).slice(0, 5))

// ---------------------------------------------------------------- entrada numérica
const valor = ref('') // texto digitado, com vírgula decimal
const config = computed(() => MODOS[modo.value])
const digitado = computed(() => (valor.value === '' ? null : Number(valor.value.replace(',', '.'))))
const valido = computed(() => digitado.value !== null && digitado.value > 0 && digitado.value <= config.value.maximo)

function tecla(t) {
  let v = valor.value
  const [inteiro, decimal] = v.split(',')
  if (t === 'C') v = ''
  else if (t === 'apagar') v = v.slice(0, -1)
  else if (t === ',') {
    if (decimal !== undefined) return vibrar(VIBRA_ERRO)
    v = `${v || '0'},`
  } else if (decimal !== undefined) {
    if (decimal.length >= 2) return vibrar(VIBRA_ERRO)
    v += t
  } else if (inteiro === '0') v = t
  else if (inteiro.length >= config.value.inteiros) return vibrar(VIBRA_ERRO)
  else v += t
  vibrar(VIBRA_TECLA)
  valor.value = v
}

/** Valor exibido com separador de milhar: "1.234,5". */
const textoDisplay = computed(() => {
  if (!valor.value) return '0'
  const [inteiro, decimal] = valor.value.split(',')
  const milhar = inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return decimal === undefined ? milhar : `${milhar},${decimal}`
})
// Reduz a fonte conforme a quantidade de caracteres para caber na largura (equivalente ao FittedBox).
const tamanhoFonte = computed(() => `min(7.5rem, 90cqh, ${Math.floor(150 / Math.max(textoDisplay.value.length, 3))}cqw)`)

// ---------------------------------------------------------------- avisos
const jaFeito = computed(() => (animal.value ? feitos.value[animal.value.id] ?? null : null))
// O servidor aceita uma pesagem por animal por dia (409 REGISTRO_DUPLICADO) e não tem correção de pesagem.
const pesagemBloqueada = computed(() => modo.value === 'peso' && jaFeito.value !== null)
const ultimaPesagemAnterior = computed(() => historico.value.find((h) => h.data < hoje()) ?? null)
const gmd = computed(() => {
  const u = ultimaPesagemAnterior.value
  if (modo.value !== 'peso' || !u || !valido.value) return null
  const dias = Math.round((new Date(`${hoje()}T12:00:00`) - new Date(`${u.data}T12:00:00`)) / 86400000)
  return dias > 0 ? { valor: (digitado.value - u.valor) / dias, desde: u.data } : null
})
const pesoSuspeito = computed(() => {
  const e = opcoes.value?.pecuaria?.engorda
  return !!gmd.value && !!e && (gmd.value.valor < e.gmd_suspeito_min || gmd.value.valor > e.gmd_suspeito_max)
})

// ---------------------------------------------------------------- salvar
const salvando = ref(false)
async function salvar() {
  if (salvando.value || !animal.value) return
  if (pesagemBloqueada.value) {
    vibrar(VIBRA_ERRO)
    toast.error(`${animal.value.brinco} já foi pesado hoje.`, { description: 'Só vale uma pesagem por animal por dia.' })
    return
  }
  if (!valido.value) {
    vibrar(VIBRA_ERRO)
    toast.error(digitado.value > config.value.maximo
      ? `Valor acima do máximo (${numero(config.value.maximo, 2)} ${config.value.unidade}).`
      : `Digite ${modo.value === 'peso' ? 'um peso' : 'uma quantidade'} maior que zero.`)
    return
  }
  salvando.value = true
  const a = animal.value
  const dia = hoje()
  const quantidade = valor.value.replace(',', '.')
  const quem = [a.brinco, a.nome].filter(Boolean).join(' · ')
  const texto = `${numero(digitado.value, config.value.casas)} ${config.value.unidade}`
  const req = modo.value === 'peso'
    ? { metodo: 'post', url: '/pesagens', descricao: `Pesagem de ${dataBr(dia)} · ${quem}`,
        dados: { uuid: novoUuid(), animal_id: a.id, data_pesagem: dia, peso_kg: quantidade } }
    : { metodo: 'put', url: '/producoes-leite/turno', descricao: `Produção de leite de ${dataBr(dia)} (${nomeTurno(periodo.value)}) · ${quem}`,
        dados: { data_pesagem: dia, periodo: periodo.value, registros: [{ animal_id: a.id, quantidade_litros: quantidade }] } }
  try {
    const { offline } = await enviarOuGuardar(req)
    vibrar(VIBRA_SALVOU)
    if (offline) avisarGuardadoOffline(`${config.value.nome} de ${a.brinco} (${texto})`)
    else {
      toast.success(`${config.value.nome} de ${a.brinco} salvo: ${texto}`, { duration: 2500 }) // curto: cobre o brinco no topo
      const novo = { data: dia, periodo: modo.value === 'leite' ? periodo.value : null, valor: digitado.value, pendente: false }
      if (modo.value === 'peso') {
        pesadosHoje.value = { ...pesadosHoje.value, [a.id]: digitado.value }
        historicoServidor.value = [novo, ...historicoServidor.value]
      } else {
        leiteDoTurno.value = { ...leiteDoTurno.value, [a.id]: digitado.value }
        historicoServidor.value = [novo, ...historicoServidor.value.filter((h) => ordemHistorico(h) !== ordemHistorico(novo))]
      }
    }
    valor.value = ''
    if (emFila.value) {
      const prox = proximoDaFila()
      if (prox) irPara(prox, { daFila: true })
      else toast.info('Fila concluída', { description: 'Todos os animais da fila já têm registro.' })
    }
  } catch (e) {
    vibrar(VIBRA_ERRO)
    toast.error('Não foi possível salvar', { description: e.mensagens?.join(' ') ?? String(e) })
  } finally {
    salvando.value = false
  }
}

// ---------------------------------------------------------------- busca
const buscaAberta = ref(false)
const termo = ref('')
const todos = computed(() => {
  const vistos = new Map()
  for (const a of [...rebanho.value, ...filaPeso.value, ...filaLeite.value]) if (!vistos.has(a.id)) vistos.set(a.id, a)
  return [...vistos.values()]
})
const encontrados = computed(() => {
  const q = termo.value.trim().toLowerCase()
  if (!q) return fila.value.slice(0, 30)
  const exatos = todos.value.filter((a) => a.brinco.toLowerCase() === q)
  const outros = todos.value.filter((a) => a.brinco.toLowerCase() !== q && `${a.brinco} ${a.nome ?? ''}`.toLowerCase().includes(q))
  return [...exatos, ...outros].slice(0, 30)
})
function abrirBusca() {
  termo.value = ''
  buscaAberta.value = true
  carregarListas()
}
function escolher(a) {
  if (!a) return
  buscaAberta.value = false
  irPara(a, { daFila: false })
}

// ---------------------------------------------------------------- teclado físico (desktop ou leitor que digita)
useEventListener(window, 'keydown', (e) => {
  if (buscaAberta.value || e.ctrlKey || e.metaKey || e.altKey || e.target.closest?.('input, textarea, [role="dialog"]')) return
  if (/^\d$/.test(e.key)) tecla(e.key)
  else if (e.key === ',' || e.key === '.') tecla(',')
  else if (e.key === 'Backspace') tecla('apagar')
  else if (e.key === 'Escape' || e.key === 'Delete') tecla('C')
  else if (e.key === 'Enter') salvar()
  else return
  e.preventDefault()
})

// ---------------------------------------------------------------- início
watch(() => props.id, async (id) => {
  valor.value = ''
  if (id) {
    await carregarAnimal(id)
    carregarHistorico()
    return
  }
  // Sem animal: começa pelo primeiro da fila (leite, se houver vaca em lactação).
  await carregarListas()
  if (!modoDaUrl()) modo.value = filaLeite.value.length ? 'leite' : 'peso'
  const prox = proximoDaFila() ?? fila.value[0]
  if (prox) irPara(prox, { daFila: true })
  else erroAnimal.value = modo.value === 'peso' ? 'Nenhum animal de corte ativo.' : 'Nenhuma vaca em lactação.'
}, { immediate: true })
watch(modo, carregarHistorico)
carregarListas()

function voltar() {
  if (window.history.state?.back) router.back()
  else router.push(modo.value === 'leite' ? '/ordenha' : '/pesagens')
}

const TECLAS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'C', '0', ',']
</script>

<template>
  <div class="flex h-dvh flex-col bg-background text-foreground select-none">
    <!-- Animal -->
    <header class="border-b">
      <div class="mx-auto flex w-full max-w-xl items-center gap-2 px-2 py-2 sm:px-3">
        <Button variant="ghost" size="icon-lg" aria-label="Voltar" @click="voltar"><ArrowLeft class="size-6" /></Button>
        <AvatarAnimal v-if="animal" :animal="animal" :tamanho="44" class="hidden shrink-0 min-[400px]:block" />
        <div class="min-w-0 flex-1">
          <h1 class="truncate text-xl leading-tight font-black tracking-tight sm:text-2xl">
            ANIMAL: <span class="font-mono">{{ animal?.brinco ?? (carregandoAnimal ? '…' : '—') }}</span>
          </h1>
          <p class="truncate text-sm font-semibold text-muted-foreground">
            <template v-if="animal">{{ [animal.nome, animal.categoria_label].filter(Boolean).join(' · ') }}</template>
            <template v-if="emFila && fila.length"> · faltam {{ faltamNaFila }}</template>
          </p>
        </div>
        <IndicadorSincronizacao />
        <Button variant="outline" size="icon-lg" class="size-11" aria-label="Buscar animal" @click="abrirBusca"><Search class="size-5" /></Button>
        <Button variant="outline" class="h-11 px-3 font-bold" aria-label="Próximo da fila" @click="pular">
          <SkipForward class="size-5" /><span class="hidden sm:inline">Próximo</span>
        </Button>
      </div>
    </header>

    <main class="mx-auto flex min-h-0 w-full max-w-xl flex-1 flex-col gap-2 px-3 pt-2 pb-3 [@media(max-height:640px)]:gap-1.5 [@media(max-height:640px)]:pb-2">
      <!-- Modo -->
      <div role="tablist" aria-label="Tipo de registro" class="grid shrink-0 grid-cols-2 gap-1 rounded-xl border-2 border-foreground/15 bg-muted p-1">
        <button v-for="(m, chave) in MODOS" :key="chave" role="tab" type="button" :aria-selected="modo === chave"
                :disabled="chave === 'leite' && !podeLeite"
                class="h-12 rounded-lg text-lg font-black tracking-wide transition-colors disabled:opacity-35 [@media(max-height:640px)]:h-10"
                :class="modo === chave ? 'bg-foreground text-background shadow' : 'text-foreground/70'"
                @click="trocarModo(chave)">
          {{ m.rotulo }}
        </button>
      </div>

      <div v-if="modo === 'leite'" class="grid shrink-0 grid-cols-2 gap-2" role="radiogroup" aria-label="Turno">
        <button v-for="t in TURNOS" :key="t.value" type="button" role="radio" :aria-checked="periodo === t.value"
                class="flex h-10 items-center justify-center gap-2 rounded-lg border-2 text-base font-bold"
                :class="periodo === t.value ? 'border-foreground bg-foreground/10' : 'border-transparent text-muted-foreground'"
                @click="periodo = t.value; vibrar(VIBRA_TECLA)">
          <component :is="t.icone" class="size-5" /> {{ t.label }}
        </button>
      </div>

      <!-- Últimos 3 registros -->
      <section aria-label="Últimos registros" class="grid shrink-0 grid-cols-3 divide-x-2 rounded-xl border-2">
        <div v-for="h in historico" :key="`${h.data}${h.periodo}${h.valor}${h.pendente}`" class="min-w-0 px-2 py-1.5 text-center [@media(max-height:640px)]:py-0.5">
          <p class="flex items-center justify-center gap-1 truncate text-xs font-bold text-muted-foreground uppercase">
            {{ dataCurta(h.data) }}<template v-if="h.periodo"> · {{ h.periodo === 'TARDE' ? 'T' : 'M' }}</template>
            <CloudUpload v-if="h.pendente" class="size-3.5 shrink-0" aria-label="Guardado no aparelho, ainda não enviado" />
          </p>
          <p class="truncate text-lg font-black tabular-nums">{{ numero(h.valor, 1) }}<span class="ml-0.5 text-xs font-bold">{{ config.unidade }}</span></p>
        </div>
        <p v-if="!historico.length" class="col-span-3 px-3 py-3 text-center text-sm font-semibold text-muted-foreground">
          {{ animal ? 'Sem registros anteriores' : '—' }}
        </p>
      </section>

      <!-- Valor (avisos no topo da caixa, para não roubar altura do teclado) -->
      <div class="relative flex min-h-20 flex-1 flex-col overflow-hidden rounded-2xl border-2 border-foreground/80">
        <p v-if="jaFeito !== null && animal" class="flex shrink-0 items-center gap-2 bg-amber-100 px-3 py-1 text-sm leading-tight font-bold text-amber-950 dark:bg-amber-900/60 dark:text-amber-50">
          <TriangleAlert class="size-4 shrink-0" />
          <span v-if="modo === 'leite'">Já tem {{ numero(jaFeito, 1) }} L neste turno. Salvar substitui.</span>
          <span v-else>Já pesado hoje: {{ numero(jaFeito, 1) }} kg. Só vale uma pesagem por dia.</span>
        </p>
        <p v-else-if="pesoSuspeito" class="flex shrink-0 items-center gap-2 bg-red-100 px-3 py-1 text-sm leading-tight font-bold text-red-950 dark:bg-red-900/60 dark:text-red-50">
          <TriangleAlert class="size-4 shrink-0" />
          <span>Confira o peso: {{ numero(gmd.valor, 2) }} kg/dia desde {{ dataBr(gmd.desde).slice(0, 5) }}.</span>
        </p>
        <p v-else-if="dadosDoAparelho" class="shrink-0 px-3 pt-1 text-center text-xs font-semibold text-muted-foreground">Sem internet: usando os dados guardados no aparelho.</p>

        <div class="relative flex min-h-0 flex-1 items-center justify-center px-14 [container-type:size]">
          <p v-if="erroAnimal && !animal" class="px-2 text-center text-lg font-bold">
            {{ erroAnimal }}
            <Button variant="outline" class="mt-3 h-12 w-full text-base font-bold" @click="abrirBusca"><Search /> Buscar animal</Button>
          </p>
          <p v-else class="flex items-baseline gap-2 leading-none font-black tracking-tight tabular-nums" :class="{ 'text-muted-foreground/50': !valor }"
             aria-live="polite" :aria-label="`${textoDisplay} ${config.unidade}`">
            <span :style="{ fontSize: tamanhoFonte }">{{ textoDisplay }}</span>
            <span class="text-2xl font-black text-muted-foreground">{{ config.unidade }}</span>
          </p>
          <button type="button" aria-label="Apagar último dígito" class="absolute top-1/2 right-1 flex size-12 -translate-y-1/2 items-center justify-center rounded-xl active:bg-muted"
                  @click="tecla('apagar')">
            <Delete class="size-7" />
          </button>
        </div>
      </div>

      <!-- Teclado -->
      <div class="grid min-h-46 max-h-80 flex-[1.4] grid-cols-3 grid-rows-4 gap-2">
        <button v-for="t in TECLAS" :key="t" type="button"
                class="flex items-center justify-center rounded-xl border-2 bg-card text-4xl font-black shadow-xs transition-colors active:bg-foreground active:text-background"
                :class="{ 'text-destructive': t === 'C' }" :aria-label="t === 'C' ? 'Limpar' : t === ',' ? 'Vírgula' : t"
                @click="tecla(t)">
          {{ t }}
        </button>
      </div>

      <button type="button" :disabled="!animal || salvando || pesagemBloqueada"
              class="flex min-h-16 w-full shrink-0 items-center justify-center gap-3 rounded-2xl bg-emerald-700 px-4 py-4 text-2xl font-black tracking-wide text-white shadow-md transition-colors active:bg-emerald-900 disabled:opacity-40"
              :class="{ 'opacity-60': !valido }" @click="salvar">
        <Check class="size-8" stroke-width="3" />
        <span class="truncate">{{ salvando ? 'SALVANDO…' : pesagemBloqueada ? 'JÁ PESADO HOJE' : jaFeito !== null ? 'SUBSTITUIR' : 'SALVAR' }}</span>
      </button>
    </main>
  </div>

  <Dialog v-model:open="buscaAberta">
    <DialogContent class="top-4 translate-y-0 sm:top-[10%] sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Buscar animal</DialogTitle>
        <DialogDescription>Digite o brinco ou o nome. Enter abre o primeiro da lista.</DialogDescription>
      </DialogHeader>
      <Input v-model="termo" autofocus inputmode="search" autocomplete="off" placeholder="Brinco ou nome" class="h-12 text-lg"
             @keydown.enter.prevent="escolher(encontrados[0])" />
      <ul class="-mx-2 max-h-[55dvh] overflow-y-auto">
        <li v-for="a in encontrados" :key="a.id">
          <button type="button" class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left hover:bg-muted active:bg-muted" @click="escolher(a)">
            <AvatarAnimal :animal="a" :tamanho="36" />
            <span class="min-w-0 flex-1">
              <span class="block truncate font-mono text-base font-bold">{{ a.brinco }}</span>
              <span class="block truncate text-xs text-muted-foreground">{{ [a.nome, a.categoria_label].filter(Boolean).join(' · ') }}</span>
            </span>
            <Check v-if="a.id in feitos" class="size-4 text-emerald-700 dark:text-emerald-400" aria-label="Já registrado" />
          </button>
        </li>
        <li v-if="!encontrados.length" class="px-2 py-6 text-center text-sm text-muted-foreground">Nenhum animal encontrado.</li>
      </ul>
    </DialogContent>
  </Dialog>
</template>
