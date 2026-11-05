<script setup>
import { computed, reactive, ref } from 'vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import http from '../api/http'
import AlertaErros from './AlertaErros.vue'
import { useCadastrosSanitarios } from '../composables/useCadastrosSanitarios'
import { useDataMinima } from '../composables/useDataMinima'
import { useEnvio } from '../composables/useEnvio'
import { useOpcoes } from '../composables/useOpcoes'
import { hoje } from '../formatos'

/**
 * Registro de vacina ou medicamento. A categoria e o produto sugerem a carência, que continua
 * editável. "Outro" libera o nome em campo livre (caso o produto não esteja cadastrado).
 * Sem `animalId`, o formulário mostra a escolha do animal.
 */
const props = defineProps({ animalId: { type: Number, default: null } })
const emit = defineEmits(['salvo', 'cancelar'])

const SEM_CATEGORIA = 'SEM_CATEGORIA'
const OUTRO = 'OUTRO'

const { opcoes } = useOpcoes()
const dataMinima = useDataMinima()
const { categoriasAtivas, produtosAtivos, pronto } = useCadastrosSanitarios()
const { enviando, mensagens, campos, enviar } = useEnvio()

const form = reactive({
  animal_id: '', data_aplicacao: hoje(), tipo: 'VACINA', categoria: SEM_CATEGORIA, produto: OUTRO, nome: '', dias: '',
})

const animais = ref([])
if (!props.animalId) {
  http.get('/animais', { params: { por_pagina: 200 } }).then((r) => (animais.value = r.data.data)).catch((e) => (mensagens.value = e.mensagens))
}
pronto?.catch?.((e) => (mensagens.value = e.mensagens))

const categoriasDoTipo = computed(() => categoriasAtivas.value.filter((c) => c.tipo === form.tipo))
const produtosDisponiveis = computed(() => produtosAtivos.value.filter((p) => form.categoria === SEM_CATEGORIA
  ? p.categoria?.tipo === form.tipo
  : String(p.categoria_sanitaria_id) === form.categoria))
const livre = computed(() => form.produto === OUTRO)

/** O prazo do produto prevalece sobre o da categoria; sem nenhum dos dois não há sugestão. */
const sugestao = computed(() => {
  const produto = produtosAtivos.value.find((p) => String(p.id) === form.produto)
  const categoria = categoriasAtivas.value.find((c) => String(c.id) === form.categoria)
  if (produto?.dias_carencia != null) return { dias: produto.dias_carencia, origem: produto.nome }
  if (categoria?.dias_carencia_padrao != null) return { dias: categoria.dias_carencia_padrao, origem: `categoria ${categoria.nome}` }
  return null
})
const aplicarSugestao = () => { form.dias = sugestao.value?.dias ?? '' }

function trocarTipo(tipo) {
  if (!tipo) return
  Object.assign(form, { tipo, categoria: SEM_CATEGORIA, produto: OUTRO })
  aplicarSugestao()
}
function trocarCategoria(categoria) {
  Object.assign(form, { categoria, produto: OUTRO })
  aplicarSugestao()
}
function trocarProduto(produto) {
  form.produto = produto
  const escolhido = produtosAtivos.value.find((p) => String(p.id) === produto)
  if (escolhido) form.categoria = String(escolhido.categoria_sanitaria_id)
  aplicarSugestao()
}

async function salvar() {
  try {
    const { data } = await enviar(() => http.post('/vacinas', {
      animal_id: props.animalId ?? (Number(form.animal_id) || null),
      tipo: form.tipo,
      categoria_sanitaria_id: form.categoria === SEM_CATEGORIA ? null : Number(form.categoria),
      produto_sanitario_id: livre.value ? null : Number(form.produto),
      nome_vacina: livre.value ? form.nome : null,
      data_aplicacao: form.data_aplicacao,
      dias_carencia_leite_carne: form.dias,
    }))
    emit('salvo', data.data)
  } catch { /* erros exibidos no formulário */ }
}

const SEL = 'flex-1 data-[state=on]:bg-primary data-[state=on]:text-primary-foreground data-[state=on]:hover:bg-primary/90'
</script>

<template>
  <form class="grid gap-4" @submit.prevent="salvar">
    <AlertaErros :mensagens="Object.keys(campos).length ? [] : mensagens" />

    <div v-if="!animalId" class="grid gap-1.5">
      <Label>Animal</Label>
      <Select v-model="form.animal_id">
        <SelectTrigger class="w-full" :aria-invalid="!!campos.animal_id"><SelectValue placeholder="Selecione…" /></SelectTrigger>
        <SelectContent>
          <SelectItem v-for="a in animais" :key="a.id" :value="String(a.id)">{{ a.brinco }}<template v-if="a.nome"> · {{ a.nome }}</template></SelectItem>
        </SelectContent>
      </Select>
      <p v-if="campos.animal_id" class="text-xs text-destructive">{{ campos.animal_id }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
      <div class="grid gap-1.5">
        <Label for="aplicacao-data">Data da aplicação</Label>
        <Input id="aplicacao-data" v-model="form.data_aplicacao" type="date" :min="dataMinima" :max="hoje()" required :aria-invalid="!!campos.data_aplicacao" />
        <p v-if="campos.data_aplicacao" class="text-xs text-destructive">{{ campos.data_aplicacao }}</p>
      </div>
      <div class="grid gap-1.5">
        <Label>Tipo</Label>
        <ToggleGroup :model-value="form.tipo" type="single" variant="outline" class="w-full" @update:model-value="trocarTipo">
          <ToggleGroupItem v-for="t in opcoes?.tipo_produto_sanitario" :key="t.value" :value="t.value" :class="SEL">{{ t.label }}</ToggleGroupItem>
        </ToggleGroup>
      </div>
      <div class="grid gap-1.5">
        <Label>Categoria</Label>
        <Select :model-value="form.categoria" @update:model-value="trocarCategoria">
          <SelectTrigger class="w-full" :aria-invalid="!!campos.categoria_sanitaria_id"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem :value="SEM_CATEGORIA">Sem categoria</SelectItem>
            <SelectItem v-for="c in categoriasDoTipo" :key="c.id" :value="String(c.id)">{{ c.nome }}</SelectItem>
          </SelectContent>
        </Select>
        <p v-if="campos.categoria_sanitaria_id" class="text-xs text-destructive">{{ campos.categoria_sanitaria_id }}</p>
      </div>
      <div class="grid gap-1.5">
        <Label>{{ form.tipo === 'VACINA' ? 'Vacina' : 'Medicamento' }}</Label>
        <Select :model-value="form.produto" @update:model-value="trocarProduto">
          <SelectTrigger class="w-full" :aria-invalid="!!campos.produto_sanitario_id"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem v-for="p in produtosDisponiveis" :key="p.id" :value="String(p.id)">{{ p.nome }}</SelectItem>
            <SelectItem :value="OUTRO">Outro (digitar o nome)</SelectItem>
          </SelectContent>
        </Select>
        <p v-if="campos.produto_sanitario_id" class="text-xs text-destructive">{{ campos.produto_sanitario_id }}</p>
      </div>
    </div>

    <div v-if="livre" class="grid gap-1.5">
      <Label for="aplicacao-nome">Nome {{ form.tipo === 'VACINA' ? 'da vacina' : 'do medicamento' }}</Label>
      <Input id="aplicacao-nome" v-model.trim="form.nome" maxlength="100" required :aria-invalid="!!campos.nome_vacina" />
      <p v-if="campos.nome_vacina" class="text-xs text-destructive">{{ campos.nome_vacina }}</p>
    </div>

    <div class="grid gap-1.5">
      <Label for="aplicacao-carencia">Carência leite/carne (dias)</Label>
      <Input id="aplicacao-carencia" v-model.number="form.dias" type="number" min="0" max="3650" required class="sm:w-40" :aria-invalid="!!campos.dias_carencia_leite_carne" />
      <p v-if="campos.dias_carencia_leite_carne" class="text-xs text-destructive">{{ campos.dias_carencia_leite_carne }}</p>
      <p class="text-xs text-muted-foreground">
        <template v-if="sugestao">Sugestão de {{ sugestao.dias }} dia(s) por {{ sugestao.origem }}. Confira a bula e use o maior prazo entre leite e carne.</template>
        <template v-else>Sem sugestão para esta opção. Informe o maior prazo da bula entre leite e carne.</template>
      </p>
    </div>

    <div class="flex justify-end gap-2">
      <Button type="button" variant="ghost" @click="emit('cancelar')">Cancelar</Button>
      <Button type="submit" :disabled="enviando">{{ enviando ? 'Salvando…' : 'Registrar aplicação' }}</Button>
    </div>
  </form>
</template>
