<script setup>
import { reactive, ref } from 'vue'
import { Download, Pencil, Plus, ShieldCheck } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import http from '../api/http'
import { baixarArquivo } from '../api/download'
import AlertaErros from '../components/AlertaErros.vue'
import CabecalhoPagina from '../components/layout/CabecalhoPagina.vue'
import { useSessao } from '../composables/useSessao'
import { entrarNaFazenda } from '../composables/useTrocaFazenda'

/** Conta do produtor (responsável pelo cliente): fazendas, cadastro de nova fazenda e exportação dos dados. */
const { fazendaId, fazendas: minhas, atualizarFazendas } = useSessao()
const conta = ref(null)
const erroCarga = ref([])

async function carregar() {
  try { conta.value = (await http.get('/cliente')).data.data } catch (e) { erroCarga.value = e.mensagens }
}
carregar()

const local = (f) => [f.municipio, f.uf].filter(Boolean).join(' / ')

const aberto = ref(false)
const editando = ref(null)
const form = reactive({ nome: '', municipio: '', uf: '', documento: '' })
const enviando = ref(false)
const erros = ref([])
const campos = ref({})

function abrir(f = null) {
  editando.value = f
  Object.assign(form, f ? { nome: f.nome, municipio: f.municipio ?? '', uf: f.uf ?? '', documento: f.documento ?? '' } : { nome: '', municipio: '', uf: '', documento: '' })
  erros.value = []
  campos.value = {}
  aberto.value = true
}

async function salvar() {
  enviando.value = true
  erros.value = []
  campos.value = {}
  const dados = { nome: form.nome, municipio: form.municipio || null, uf: form.uf || null, documento: form.documento || null }
  try {
    if (editando.value) {
      const { data } = await http.patch(`/cliente/fazendas/${editando.value.id}`, dados)
      atualizarFazendas(minhas.value.map((f) => (f.id === data.data.id ? { ...f, nome: data.data.nome } : f)))
      toast.success('Fazenda atualizada', { description: data.data.nome })
    } else {
      const { data } = await http.post('/cliente/fazendas', dados)
      atualizarFazendas([...minhas.value, data.data])
      toast.success('Fazenda cadastrada', {
        description: `${data.data.nome} já tem o plano de contas e as categorias sanitárias padrão.`,
        action: { label: 'Entrar', onClick: () => entrarNaFazenda(data.data.id) },
      })
    }
    aberto.value = false
    carregar()
  } catch (e) {
    campos.value = e.campos
    erros.value = Object.keys(e.campos).length ? [] : e.mensagens
  } finally {
    enviando.value = false
  }
}

const exportando = ref(false)
async function exportar() {
  exportando.value = true
  try { await baixarArquivo('/cliente/exportacao', 'dados.zip') } catch (e) { toast.error('Não foi possível exportar', { description: e.mensagens?.[0] }) }
  exportando.value = false
}
</script>

<template>
  <CabecalhoPagina titulo="Conta do produtor">
    <template #acoes><Button @click="abrir()"><Plus /> <span class="hidden sm:inline">Nova fazenda</span></Button></template>
  </CabecalhoPagina>

  <main class="mx-auto max-w-4xl space-y-4 px-4 py-6 md:px-6">
    <AlertaErros :mensagens="erroCarga" />
    <Skeleton v-if="!conta && !erroCarga.length" class="h-40 w-full" />

    <template v-if="conta">
      <div class="rounded-xl border bg-card p-4">
        <p class="text-sm text-muted-foreground">Produtor</p>
        <p class="text-lg font-semibold">{{ conta.nome }}</p>
        <p v-if="conta.documento" class="text-sm text-muted-foreground">{{ conta.documento }}</p>
      </div>

      <div class="overflow-hidden rounded-xl border bg-card">
        <div class="border-b px-4 py-3"><p class="font-medium">Fazendas</p></div>
        <ul class="divide-y">
          <li v-for="f in conta.fazendas" :key="f.id" class="flex items-center gap-3 px-4 py-3">
            <div class="min-w-0 flex-1">
              <p class="font-medium">{{ f.nome }} <Badge v-if="f.id === fazendaId" variant="secondary" class="ml-1">Atual</Badge></p>
              <p class="text-xs text-muted-foreground">{{ [local(f), `${f.usuarios} usuário(s) com acesso`].filter(Boolean).join(' · ') }}</p>
            </div>
            <Button v-if="f.id !== fazendaId && minhas.some((m) => m.id === f.id)" variant="ghost" size="sm" @click="entrarNaFazenda(f.id)">Entrar</Button>
            <Button variant="ghost" size="icon" :aria-label="`Editar ${f.nome}`" @click="abrir(f)"><Pencil /></Button>
          </li>
        </ul>
      </div>

      <div class="flex flex-wrap items-start gap-3 rounded-xl border bg-card p-4">
        <ShieldCheck class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
        <div class="min-w-0 flex-1">
          <p class="font-medium">Seus dados</p>
          <p class="text-sm text-muted-foreground">Baixe uma cópia completa de todas as fazendas: rebanho, ordenha, pesagens, sanidade, lotes, financeiro, estoque, fotos e anexos.</p>
        </div>
        <Button variant="outline" :disabled="exportando" @click="exportar"><Download /> {{ exportando ? 'Gerando…' : 'Exportar dados' }}</Button>
      </div>
    </template>
  </main>

  <Dialog v-model:open="aberto">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ editando ? 'Editar fazenda' : 'Nova fazenda' }}</DialogTitle>
        <DialogDescription v-if="!editando">A fazenda nasce vazia, com o plano de contas e as categorias sanitárias padrão. Você entra nela como gestor e convida a equipe em Usuários.</DialogDescription>
      </DialogHeader>
      <form id="form-fazenda" class="grid gap-3 sm:grid-cols-[1fr_6rem]" @submit.prevent="salvar">
        <AlertaErros class="sm:col-span-2" :mensagens="erros" />
        <div class="grid gap-1.5 sm:col-span-2"><Label for="f-nome">Nome</Label><Input id="f-nome" v-model.trim="form.nome" required :aria-invalid="!!campos.nome" /><p v-if="campos.nome" class="text-xs text-destructive">{{ campos.nome }}</p></div>
        <div class="grid gap-1.5"><Label for="f-mun">Município</Label><Input id="f-mun" v-model.trim="form.municipio" /></div>
        <div class="grid gap-1.5"><Label for="f-uf">UF</Label><Input id="f-uf" v-model.trim="form.uf" maxlength="2" class="uppercase" :aria-invalid="!!campos.uf" /></div>
        <div class="grid gap-1.5 sm:col-span-2"><Label for="f-doc">CPF/CNPJ ou inscrição estadual (opcional)</Label><Input id="f-doc" v-model.trim="form.documento" inputmode="numeric" /></div>
      </form>
      <DialogFooter>
        <Button variant="ghost" @click="aberto = false">Cancelar</Button>
        <Button type="submit" form="form-fazenda" :disabled="enviando">Salvar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
