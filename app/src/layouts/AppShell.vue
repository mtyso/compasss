<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, type RouteLocationNormalized } from 'vue-router'
import { CalendarCheck, Ellipsis, Plus, Sun, Wallet } from 'lucide-vue-next'
import QuickAddSheet from '@/components/QuickAddSheet.vue'
import UiToastHost from '@/components/ui/UiToastHost.vue'
import { showToast } from '@/components/ui/toast'
import { haptics } from '@/lib/haptics'
import { useMoney, useQuickAdd } from '@/stores/money'

const quick = useQuickAdd()
const money = useMoney()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const tabs = [
  { to: '/', label: 'nav.today', icon: Sun, tone: 'ink' },
  { to: '/money', label: 'nav.money', icon: Wallet, tone: 'money' },
  { to: '/tasks', label: 'nav.tasks', icon: CalendarCheck, tone: 'task' },
  { to: '/more', label: 'nav.more', icon: Ellipsis, tone: 'ink' },
] as const

const toneClasses = {
  ink: { text: 'text-ink', pill: 'bg-ink/8' },
  money: { text: 'text-money', pill: 'bg-money-soft' },
  task: { text: 'text-task', pill: 'bg-task-soft' },
} as const

/** The tab a screen belongs to: Счета and Категории live under Ещё. */
function tabOf(path: string): string {
  const first = path.split('/').filter(Boolean)[0]
  return first ? `/${first}` : '/'
}
const activeTab = computed(() => tabOf(route.path))

/** How deep a screen is: tab roots are 0, a screen opened from them 1, and so on. */
function depth(location: RouteLocationNormalized): number {
  return Math.max(0, location.path.split('/').filter(Boolean).length - 1)
}

// Switching tabs fades through; going deeper slides in from the right, coming back from the left.
// Scroll positions are remembered per screen and restored when you come back to it.
const transition = ref<'through' | 'push' | 'pop'>('through')
const scroller = ref<HTMLElement | null>(null)
const positions = new Map<string, number>()
let restore = 0

const removeGuard = router.beforeEach((to, from) => {
  if (scroller.value) positions.set(from.fullPath, scroller.value.scrollTop)
  const delta = depth(to) - depth(from)
  const sameTab = tabOf(to.path) === tabOf(from.path)
  transition.value = sameTab && delta > 0 ? 'push' : sameTab && delta < 0 ? 'pop' : 'through'
  restore = transition.value === 'push' ? 0 : (positions.get(to.fullPath) ?? 0)
})
onBeforeUnmount(removeGuard)

function onEnter(): void {
  if (scroller.value) scroller.value.scrollTop = restore
}

/** Tapping the tab you are already on scrolls back to the top, as on Android. */
function onTab(to: string): void {
  if (activeTab.value !== to) haptics.selection()
  else if (route.path === to) scroller.value?.scrollTo({ top: 0, behavior: 'smooth' })
}

function openQuickAdd(): void {
  haptics.impact('light')
  quick.start()
}

// «Операция удалена · Вернуть» after deleting from the sheet.
watch(
  () => quick.deletedId,
  (id) => {
    if (id === null) return
    quick.deletedId = null
    showToast({
      message: t('quickAdd.deleted'),
      action: { label: t('quickAdd.undo'), run: () => money.restoreTransaction(id) },
    })
  },
)
</script>

<template>
  <div class="shell mx-auto flex h-dvh max-w-xl flex-col">
    <!-- Keeps scrolled content out from under the status bar (and Telegram's header). -->
    <div class="pointer-events-none fixed inset-x-0 top-0 z-30 h-[var(--shell-top)] bg-bg" aria-hidden="true" />

    <main ref="scroller" data-scroller class="min-h-0 flex-1 overflow-y-auto overscroll-y-contain px-4 pt-[var(--shell-top)] pb-10">
      <RouterView v-slot="{ Component, route: current }">
        <Transition :name="transition" mode="out-in" @enter="onEnter">
          <component :is="Component" :key="current.path" />
        </Transition>
      </RouterView>
    </main>

    <div class="pointer-events-none fixed inset-x-0 z-30 bottom-[calc(4.25rem+var(--safe-bottom))]">
      <UiToastHost />
    </div>

    <nav class="relative z-10 bg-surface pb-[var(--safe-bottom)] shadow-[0_-1px_0_var(--c-line)]" :aria-label="$t('appName')">
      <div class="grid h-16 grid-cols-5 items-center px-1">
        <template v-for="(tab, index) in tabs" :key="tab.to">
          <span v-if="index === 2" class="grid place-items-center">
            <button
              type="button"
              class="press grid size-13 place-items-center rounded-[1.125rem] bg-ink text-surface shadow-fab"
              :aria-label="$t('nav.add')"
              @click="openQuickAdd"
            >
              <Plus class="size-6" :stroke-width="2.5" />
            </button>
          </span>
          <RouterLink
            :to="tab.to"
            class="tab flex h-full flex-col items-center justify-center gap-1 text-micro"
            :class="activeTab === tab.to ? [toneClasses[tab.tone].text, 'font-semibold'] : 'font-medium text-ink-3'"
            :aria-current="activeTab === tab.to ? 'page' : undefined"
            @click="onTab(tab.to)"
          >
            <span class="relative grid h-8 w-14 place-items-center">
              <span class="pill absolute inset-0 rounded-full" :class="[toneClasses[tab.tone].pill, { on: activeTab === tab.to }]" />
              <component :is="tab.icon" class="relative size-[22px]" :stroke-width="activeTab === tab.to ? 2.2 : 1.8" />
            </span>
            {{ $t(tab.label) }}
          </RouterLink>
        </template>
      </div>
    </nav>

    <QuickAddSheet />
  </div>
</template>

<style scoped>
.shell {
  --shell-top: max(0.75rem, var(--safe-top));
}

/* Active tab: the pill grows out from the icon, like Android's navigation bar. */
.pill {
  opacity: 0;
  transform: scaleX(0.4);
  transition:
    transform var(--dur-spring) var(--ease-spring),
    opacity var(--dur-fast) var(--ease-standard);
}

.pill.on {
  opacity: 1;
  transform: none;
}

.tab:active .pill {
  opacity: 1;
  transform: scale(0.94);
}

@media (prefers-reduced-motion: reduce) {
  .pill {
    transform: none;
  }
}
</style>
