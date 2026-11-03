<script setup>
import { computed } from 'vue'
import { ArrowLeftRight, Beef, Boxes, CalendarClock, Fence, LayoutDashboard, Milk, Scale, Settings2, Tags, UserCog, Wallet, Waves } from '@lucide/vue'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { useSessao } from '@/composables/useSessao'

defineProps({ recolhido: { type: Boolean, default: false } })
const emit = defineEmits(['navegou'])

// `gestor: true` = só aparece para o perfil Gestor. `secao` abre um grupo com título.
const TODOS = [
  { rotulo: 'Painel', icone: LayoutDashboard, para: '/', exato: true, gestor: true },
  { rotulo: 'Ordenha', icone: Milk, para: '/ordenha' },
  { rotulo: 'Pesagens', icone: Scale, para: '/pesagens' },
  { rotulo: 'Rebanho', icone: Tags, para: '/animais' },
  { rotulo: 'Lotes', icone: Boxes, para: '/lotes', gestor: true },
  { rotulo: 'Piquetes', icone: Fence, para: '/piquetes' },
  { rotulo: 'Abate', icone: Beef, para: '/abate', gestor: true },
  { secao: 'Financeiro', rotulo: 'Visão geral', icone: Wallet, para: '/financeiro', exato: true, gestor: true },
  { rotulo: 'Lançamentos', icone: ArrowLeftRight, para: '/financeiro/lancamentos', gestor: true },
  { rotulo: 'A pagar e receber', icone: CalendarClock, para: '/financeiro/contas-a-pagar-e-receber', gestor: true },
  { rotulo: 'Fluxo de caixa', icone: Waves, para: '/financeiro/fluxo-de-caixa', gestor: true },
  { rotulo: 'Contas e categorias', icone: Settings2, para: '/financeiro/cadastros', gestor: true },
  { secao: 'Administração', rotulo: 'Usuários', icone: UserCog, para: '/usuarios', gestor: true },
]
const { ehGestor } = useSessao()
const itens = computed(() => TODOS.filter((i) => !i.gestor || ehGestor.value))
</script>

<template>
  <nav class="space-y-1">
    <template v-for="item in itens" :key="item.para">
    <p v-if="item.secao && !recolhido" class="px-2.5 pt-4 pb-1 text-[11px] font-semibold uppercase tracking-wider text-sidebar-foreground/50">{{ item.secao }}</p>
    <div v-else-if="item.secao" class="mx-2 my-2 border-t border-sidebar-border" />
    <Tooltip :disabled="!recolhido">
      <TooltipTrigger as-child>
        <RouterLink :to="item.para" custom v-slot="{ href, navigate, isActive, isExactActive }">
          <a
            :href="href"
            class="flex h-9 items-center gap-3 rounded-md px-2.5 text-sm font-medium transition-colors hover:bg-sidebar-accent/60"
            :class="[(item.exato ? isExactActive : isActive) ? 'bg-sidebar-accent text-sidebar-accent-foreground' : 'text-sidebar-foreground/80',
                     recolhido ? 'justify-center' : '']"
            @click="(e) => { navigate(e); emit('navegou') }"
          >
            <component :is="item.icone" class="size-4 shrink-0" />
            <span v-if="!recolhido" class="truncate">{{ item.rotulo }}</span>
          </a>
        </RouterLink>
      </TooltipTrigger>
      <TooltipContent side="right">{{ item.rotulo }}</TooltipContent>
    </Tooltip>
    </template>
  </nav>
</template>
