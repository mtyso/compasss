<script setup lang="ts">
import { ref } from 'vue'
import { CalendarCheck, Ellipsis, Plus, Sun, Wallet } from 'lucide-vue-next'
import QuickAddSheet from '@/components/QuickAddSheet.vue'

const quickAddOpen = ref(false)

const tabs = [
  { to: '/', name: 'today', label: 'nav.today', icon: Sun, active: 'text-ink' },
  { to: '/money', name: 'money', label: 'nav.money', icon: Wallet, active: 'text-money' },
  { to: '/tasks', name: 'tasks', label: 'nav.tasks', icon: CalendarCheck, active: 'text-task' },
  { to: '/more', name: 'more', label: 'nav.more', icon: Ellipsis, active: 'text-ink' },
] as const
</script>

<template>
  <div class="mx-auto flex h-full max-w-xl flex-col">
    <main class="flex-1 overflow-y-auto px-4 pb-6 pt-[max(1rem,env(safe-area-inset-top))]">
      <RouterView />
    </main>

    <nav
      class="grid grid-cols-5 items-end border-t border-line bg-surface px-2 pt-2 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
    >
      <template v-for="(tab, index) in tabs" :key="tab.name">
        <button
          v-if="index === 2"
          type="button"
          class="mx-auto -mt-1 grid size-12 place-items-center rounded-full bg-ink text-surface shadow-sm transition active:scale-95"
          :aria-label="$t('nav.add')"
          @click="quickAddOpen = true"
        >
          <Plus class="size-6" :stroke-width="2.4" />
        </button>
        <RouterLink
          :to="tab.to"
          class="flex flex-col items-center gap-1 text-[11px] text-ink-3"
          :exact-active-class="`${tab.active} font-semibold`"
        >
          <component :is="tab.icon" class="size-5" :stroke-width="1.9" />
          {{ $t(tab.label) }}
        </RouterLink>
      </template>
    </nav>

    <QuickAddSheet v-model:open="quickAddOpen" />
  </div>
</template>
