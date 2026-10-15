<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronDown, LogOut, Monitor, Moon, Sun, UserRound } from '@lucide/vue'
import { useColorMode } from '@vueuse/core'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel,
  DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import http from '@/api/http'
import { useSessao } from '@/composables/useSessao'

/** Canto superior direito: alternar tema e menu do usuário (perfil, tema, sair). */
const router = useRouter()
const { usuario, encerrar } = useSessao()

// 'auto' segue o tema do sistema operacional.
const { store: tema, state: temaEfetivo } = useColorMode({ emitAuto: true })
const TEMAS = [
  { value: 'light', label: 'Claro', icone: Sun },
  { value: 'dark', label: 'Escuro', icone: Moon },
  { value: 'auto', label: 'Do sistema', icone: Monitor },
]
const escuro = computed(() => temaEfetivo.value === 'dark')
const alternarTema = () => (tema.value = escuro.value ? 'light' : 'dark')

const iniciais = computed(() => (usuario.value?.nome ?? '?').split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase())

async function sair() {
  try { await http.post('/logout') } catch { /* encerra localmente mesmo sem rede */ }
  encerrar()
  router.replace('/entrar')
}
</script>

<template>
  <div class="flex items-center gap-1">
    <Tooltip>
      <TooltipTrigger as-child>
        <Button variant="ghost" size="icon" :aria-label="escuro ? 'Usar tema claro' : 'Usar tema escuro'" @click="alternarTema">
          <component :is="escuro ? Sun : Moon" />
        </Button>
      </TooltipTrigger>
      <TooltipContent>{{ escuro ? 'Tema claro' : 'Tema escuro' }}</TooltipContent>
    </Tooltip>

    <DropdownMenu v-if="usuario">
      <DropdownMenuTrigger as-child>
        <Button variant="ghost" class="h-9 gap-2 px-1.5" aria-label="Menu do usuário">
          <span class="flex size-7 items-center justify-center rounded-full bg-primary/15 text-xs font-semibold text-primary">{{ iniciais }}</span>
          <span class="hidden max-w-32 truncate text-sm font-medium lg:inline">{{ usuario.nome }}</span>
          <ChevronDown class="hidden size-4 text-muted-foreground lg:inline" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" class="w-60">
        <DropdownMenuLabel class="font-normal">
          <p class="truncate text-sm font-medium">{{ usuario.nome }}</p>
          <p class="truncate text-xs text-muted-foreground">{{ usuario.perfil_label }} · {{ usuario.usuario }}</p>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem @select="router.push('/perfil')"><UserRound /> Meu perfil</DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuLabel class="text-xs text-muted-foreground">Tema</DropdownMenuLabel>
        <DropdownMenuRadioGroup v-model="tema">
          <DropdownMenuRadioItem v-for="t in TEMAS" :key="t.value" :value="t.value"><component :is="t.icone" /> {{ t.label }}</DropdownMenuRadioItem>
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator />
        <DropdownMenuItem variant="destructive" @select="sair"><LogOut /> Sair</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  </div>
</template>
