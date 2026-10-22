<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, type RouteLocationNormalized } from 'vue-router'
import {
  CalendarCheck, CalendarClock, HandCoins, LayoutGrid, Lightbulb, PieChart, Plus, Scale, Settings, Sun, Target, Wallet, WalletCards,
} from 'lucide-vue-next'
import QuickAddSheet from '@/components/QuickAddSheet.vue'
import CompassMark from '@/components/ui/CompassMark.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiToastHost from '@/components/ui/UiToastHost.vue'
import { showToast } from '@/components/ui/toast'
import { haptics } from '@/lib/haptics'
import { useAuth } from '@/stores/auth'
import { useMoney, useQuickAdd } from '@/stores/money'

const quick = useQuickAdd()
const money = useMoney()
const auth = useAuth()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const tabs = [
  { to: '/', label: 'nav.today', icon: Sun },
  { to: '/money', label: 'nav.money', icon: Wallet },
  { to: '/tasks', label: 'nav.tasks', icon: CalendarCheck },
  { to: '/goals', label: 'nav.goals', icon: Target },
] as const

// On a wide screen the sidebar lists the screens a phone reaches through settings as well.
const sideLinks = [
  [
    { name: 'today', label: 'nav.today', icon: Sun },
    { name: 'money', label: 'nav.money', icon: Wallet },
    { name: 'tasks', label: 'nav.tasks', icon: CalendarCheck },
    { name: 'goals', label: 'nav.goals', icon: Target, also: ['goal'] },
    { name: 'advice', label: 'more.advice', icon: Lightbulb },
    { name: 'can-afford', label: 'advice.canAfford', icon: Scale },
  ],
  [
    { name: 'accounts', label: 'more.accounts', icon: WalletCards, also: ['account-new', 'account-edit'] },
    { name: 'categories', label: 'more.categories', icon: LayoutGrid },
    { name: 'budget', label: 'more.budget', icon: PieChart },
    { name: 'income', label: 'more.income', icon: HandCoins },
    { name: 'payments', label: 'more.payments', icon: CalendarClock },
  ],
] as const

function isActive(link: { name: string; also?: readonly string[] }): boolean {
  const name = String(route.name ?? '')
  return name === link.name || !!link.also?.includes(name)
}

/** The tab a screen belongs to: Счета and Категории live under settings, reached from «Сегодня». */
function tabOf(path: string): string {
  const first = path.split('/').filter(Boolean)[0]
  return first && first !== 'more' ? `/${first}` : '/'
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
  <div class="shell flex h-dvh">
    <!-- Wide screens: navigation as a sidebar, the main action at its top. -->
    <aside class="hidden w-64 shrink-0 flex-col gap-5 bg-chrome px-3 pt-5 pb-3 shadow-[1px_0_0_var(--c-line)] md:flex">
      <RouterLink to="/" class="flex items-center gap-2.5 self-start rounded-lg px-2 py-1" @click="onTab('/')">
        <CompassMark :size="26" />
        <span class="text-[1.0625rem] font-bold tracking-[-0.015em]">{{ $t('appName') }}</span>
      </RouterLink>

      <UiButton block class="!h-11 !justify-start !rounded-[0.625rem] !px-3" @click="openQuickAdd">
        <template #icon><Plus class="size-5" :stroke-width="2.4" /></template>
        {{ $t('nav.add') }}
      </UiButton>

      <nav class="no-scrollbar -mx-1 flex min-h-0 flex-1 flex-col overflow-y-auto px-1" :aria-label="$t('appName')">
        <template v-for="(links, index) in sideLinks" :key="index">
          <span v-if="index" class="mx-2.5 my-3 h-px shrink-0 bg-line" aria-hidden="true" />
          <RouterLink
            v-for="link in links"
            :key="link.name"
            :to="{ name: link.name }"
            class="side-link"
            :class="{ 'is-on': isActive(link) }"
            :aria-current="isActive(link) ? 'page' : undefined"
          >
            <component :is="link.icon" class="size-[18px] shrink-0" :stroke-width="isActive(link) ? 2.2 : 1.9" />
            <span class="truncate">{{ $t(link.label) }}</span>
          </RouterLink>
        </template>
      </nav>

      <RouterLink :to="{ name: 'more' }" class="side-link !h-auto !py-2" :class="{ 'is-on': isActive({ name: 'more', also: ['pin-setup'] }) }">
        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-money text-sm font-semibold text-on-money">
          {{ auth.user?.name.charAt(0).toUpperCase() ?? '·' }}
        </span>
        <span class="flex min-w-0 flex-1 flex-col">
          <span class="truncate text-sm font-semibold text-ink">{{ auth.user?.name }}</span>
          <span class="truncate text-caption text-ink-3">{{ $t('ui.settings') }}</span>
        </span>
        <Settings class="size-[18px] shrink-0 text-ink-3" :stroke-width="1.9" />
      </RouterLink>
    </aside>

    <div class="relative flex min-w-0 flex-1 flex-col">
      <!-- Keeps scrolled content out from under the status bar (and Telegram's header). -->
      <div class="pointer-events-none fixed inset-x-0 top-0 z-30 h-[var(--shell-top)] bg-bg md:hidden" aria-hidden="true" />

      <main
        ref="scroller"
        data-scroller
        class="min-h-0 flex-1 overflow-y-auto overscroll-y-contain px-4 pt-[var(--shell-top)] pb-10 md:px-10 md:pt-6 md:pb-16"
      >
        <RouterView v-slot="{ Component, route: current }">
          <Transition :name="transition" mode="out-in" @enter="onEnter">
            <component :is="Component" :key="current.path" />
          </Transition>
        </RouterView>
      </main>

      <div class="pointer-events-none absolute inset-x-0 z-30 bottom-[calc(4.5rem+var(--safe-bottom))] md:bottom-6">
        <UiToastHost />
      </div>

      <!-- Phones: bottom bar, with «Записать» in the middle where the thumb is. -->
      <nav class="relative z-10 bg-chrome pb-[var(--safe-bottom)] shadow-[0_-1px_0_var(--c-line)] md:hidden" :aria-label="$t('appName')">
        <div class="grid h-16 grid-cols-5 items-center px-1">
          <template v-for="(tab, index) in tabs" :key="tab.to">
            <span v-if="index === 2" class="grid place-items-center">
              <button
                type="button"
                class="press grid size-12 place-items-center rounded-full bg-money text-on-money shadow-fab"
                :aria-label="$t('nav.add')"
                @click="openQuickAdd"
              >
                <Plus class="size-6" :stroke-width="2.4" />
              </button>
            </span>
            <RouterLink
              :to="tab.to"
              class="tab flex h-full flex-col items-center justify-center gap-1 text-micro"
              :class="activeTab === tab.to ? 'font-semibold text-ink' : 'font-medium text-ink-3'"
              :aria-current="activeTab === tab.to ? 'page' : undefined"
              @click="onTab(tab.to)"
            >
              <span class="relative grid h-8 w-14 place-items-center">
                <span class="pill absolute inset-0 rounded-full bg-ink/[0.08]" :class="{ on: activeTab === tab.to }" />
                <component :is="tab.icon" class="relative size-[22px]" :stroke-width="activeTab === tab.to ? 2.2 : 1.8" />
              </span>
              {{ $t(tab.label) }}
            </RouterLink>
          </template>
        </div>
      </nav>
    </div>

    <QuickAddSheet />
  </div>
</template>

<style scoped>
.shell {
  --shell-top: max(0.75rem, var(--safe-top));
}

.side-link {
  display: flex;
  height: 2.5rem;
  flex-shrink: 0;
  align-items: center;
  gap: 0.75rem;
  border-radius: 0.625rem;
  padding-inline: 0.625rem;
  font-size: 0.9375rem;
  font-weight: 500;
  color: var(--c-ink-2);
  transition:
    background-color var(--dur-fast) var(--ease-standard),
    color var(--dur-fast) var(--ease-standard);
}

.side-link:hover {
  background-color: color-mix(in srgb, var(--c-ink) 5%, transparent);
  color: var(--c-ink);
}

.side-link.is-on {
  background-color: color-mix(in srgb, var(--c-ink) 8%, transparent);
  color: var(--c-ink);
  font-weight: 600;
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
