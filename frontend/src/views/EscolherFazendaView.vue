<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronRight, LogOut, Wheat } from '@lucide/vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import http from '../api/http'
import { useSessao } from '../composables/useSessao'
import { useSistema } from '../composables/useSistema'
import { entrarNaFazenda } from '../composables/useTrocaFazenda'
import { limparLeituras } from '../offline/cache'

/** Escolha da fazenda de trabalho, para quem tem acesso a mais de uma. */
const route = useRoute()
const router = useRouter()
const { fazendas, fazendaId, atualizarFazendas, encerrar } = useSessao()
const { produto } = useSistema()
const atualizando = ref(false)

const volta = typeof route.query.volta === 'string' && route.query.volta.startsWith('/') ? route.query.volta : null

onMounted(async () => {
  atualizando.value = true
  try { atualizarFazendas((await http.get('/eu')).data.fazendas ?? []) } catch { /* sem internet: usa a lista guardada */ }
  atualizando.value = false
  if (fazendas.value.length === 1) entrarNaFazenda(fazendas.value[0].id, volta)
})

async function sair() {
  try { await http.post('/logout') } catch { /* encerra localmente mesmo sem rede */ }
  encerrar()
  limparLeituras()
  router.replace('/entrar')
}
</script>

<template>
  <main class="flex min-h-screen w-full items-center justify-center bg-muted/40 p-4">
    <div class="w-full max-w-md space-y-6">
      <div class="flex flex-col items-center gap-3 text-center">
        <span class="flex size-12 items-center justify-center rounded-xl bg-primary text-primary-foreground"><Wheat class="size-7" /></span>
        <h1 class="text-xl font-semibold">{{ produto }}</h1>
      </div>
      <Card>
        <CardHeader>
          <CardTitle>Em qual fazenda você vai trabalhar?</CardTitle>
          <CardDescription>Dá para trocar depois, no menu do usuário.</CardDescription>
        </CardHeader>
        <CardContent class="grid gap-2">
          <button
            v-for="f in fazendas" :key="f.id" type="button"
            class="flex w-full items-center gap-3 rounded-lg border px-4 py-3 text-left transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
            :class="{ 'border-primary': f.id === fazendaId }"
            @click="entrarNaFazenda(f.id, volta)"
          >
            <span class="min-w-0 flex-1">
              <span class="block truncate font-medium">{{ f.nome }}</span>
            </span>
            <Badge variant="secondary">{{ f.perfil_label }}</Badge>
            <ChevronRight class="size-4 text-muted-foreground" />
          </button>
          <p v-if="!fazendas.length && !atualizando" class="py-4 text-center text-sm text-muted-foreground">
            Você não tem acesso a nenhuma fazenda. Fale com o gestor.
          </p>
          <Button variant="ghost" class="mt-2 justify-self-center text-muted-foreground" @click="sair"><LogOut /> Sair</Button>
        </CardContent>
      </Card>
    </div>
  </main>
</template>
