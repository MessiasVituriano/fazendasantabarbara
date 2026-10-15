<script setup>
import { computed } from 'vue'
import { Beef, Fence, LayoutDashboard, Milk, Scale, Tags, UserCog } from '@lucide/vue'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { useSessao } from '@/composables/useSessao'

defineProps({ recolhido: { type: Boolean, default: false } })
const emit = defineEmits(['navegou'])

// `gestor: true` = só aparece para o perfil Gestor.
const TODOS = [
  { rotulo: 'Painel', icone: LayoutDashboard, para: '/', exato: true, gestor: true },
  { rotulo: 'Ordenha', icone: Milk, para: '/ordenha' },
  { rotulo: 'Pesagens', icone: Scale, para: '/pesagens' },
  { rotulo: 'Rebanho', icone: Tags, para: '/animais' },
  { rotulo: 'Piquetes', icone: Fence, para: '/piquetes' },
  { rotulo: 'Abate', icone: Beef, para: '/abate', gestor: true },
  { rotulo: 'Usuários', icone: UserCog, para: '/usuarios', gestor: true },
]
const { ehGestor } = useSessao()
const itens = computed(() => TODOS.filter((i) => !i.gestor || ehGestor.value))
</script>

<template>
  <nav class="space-y-1">
    <Tooltip v-for="item in itens" :key="item.para" :disabled="!recolhido">
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
  </nav>
</template>
