<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import { CalendarCheck, Ellipsis, Plus, Sun, Wallet } from 'lucide-vue-next'
import QuickAddSheet from '@/components/QuickAddSheet.vue'
import { useMoney, useQuickAdd } from '@/stores/money'

const quick = useQuickAdd()
const money = useMoney()

const tabs = [
  { to: '/', name: 'today', label: 'nav.today', icon: Sun, active: 'text-ink' },
  { to: '/money', name: 'money', label: 'nav.money', icon: Wallet, active: 'text-money' },
  { to: '/tasks', name: 'tasks', label: 'nav.tasks', icon: CalendarCheck, active: 'text-task' },
  { to: '/more', name: 'more', label: 'nav.more', icon: Ellipsis, active: 'text-ink' },
] as const

// «Операция удалена · Вернуть» for a few seconds after deleting from the sheet.
let toastTimer: number | undefined
watch(
  () => quick.deletedId,
  (id) => {
    window.clearTimeout(toastTimer)
    if (id !== null) toastTimer = window.setTimeout(() => (quick.deletedId = null), 5000)
  },
)
onBeforeUnmount(() => window.clearTimeout(toastTimer))

async function undoDelete(): Promise<void> {
  const id = quick.deletedId
  quick.deletedId = null
  if (id !== null) await money.restoreTransaction(id)
}
</script>

<template>
  <div class="mx-auto flex h-full max-w-xl flex-col">
    <main class="flex-1 overflow-y-auto px-4 pb-6 pt-[max(1rem,env(safe-area-inset-top))]">
      <RouterView />
    </main>

    <Transition enter-from-class="opacity-0 translate-y-2" leave-to-class="opacity-0" enter-active-class="transition duration-200" leave-active-class="transition duration-150">
      <div v-if="quick.deletedId !== null" class="pointer-events-none fixed inset-x-0 bottom-24 z-10 flex justify-center px-4">
        <div class="pointer-events-auto flex items-center gap-4 rounded-2xl bg-ink px-4 py-3 text-sm text-surface shadow-lg" role="status">
          {{ $t('quickAdd.deleted') }}
          <button type="button" class="font-semibold underline-offset-2 hover:underline" @click="undoDelete">{{ $t('quickAdd.undo') }}</button>
        </div>
      </div>
    </Transition>

    <nav
      class="grid grid-cols-5 items-end border-t border-line bg-surface px-2 pt-2 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
    >
      <template v-for="(tab, index) in tabs" :key="tab.name">
        <button
          v-if="index === 2"
          type="button"
          class="mx-auto -mt-1 grid size-12 place-items-center rounded-full bg-ink text-surface shadow-sm transition active:scale-95"
          :aria-label="$t('nav.add')"
          @click="quick.start()"
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

    <QuickAddSheet />
  </div>
</template>
