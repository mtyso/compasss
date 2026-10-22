<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { CalendarClock, HandCoins, KeyRound, LayoutGrid, LogOut, PieChart, Wallet } from 'lucide-vue-next'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiRow from '@/components/ui/UiRow.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import { prefersReducedMotion } from '@/lib/motion'
import { api } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useSettings, type Theme } from '@/stores/settings'
import type { Locale } from '@/lib/format'

const settings = useSettings()
const auth = useAuth()
const serverOk = ref<boolean | null>(null)
const confirmingLogout = ref(false)

const locales: { value: Locale; label: string }[] = [
  { value: 'ru', label: 'Русский' },
  { value: 'uz', label: "O'zbekcha" },
]
const { t } = useI18n()
const themeOptions = computed(() => (['system', 'light', 'dark'] as Theme[]).map((value) => ({ value, label: t(`more.themes.${value}`) })))

const moneyLinks = [
  { name: 'accounts', label: 'more.accounts', icon: Wallet },
  { name: 'categories', label: 'more.categories', icon: LayoutGrid },
  { name: 'budget', label: 'more.budget', icon: PieChart },
  { name: 'income', label: 'more.income', icon: HandCoins },
  { name: 'payments', label: 'more.payments', icon: CalendarClock },
] as const

// Switching the theme washes the new colours over the screen from the switch, where the browser
// can do it on the compositor (View Transitions); elsewhere it simply switches.
let lastTap = { x: window.innerWidth / 2, y: window.innerHeight / 2 }
function rememberTap(event: PointerEvent): void {
  lastTap = { x: event.clientX, y: event.clientY }
}
function setTheme(next: Theme): void {
  const start = (document as Document & { startViewTransition?: (cb: () => void) => { ready: Promise<void> } }).startViewTransition
  if (!start || prefersReducedMotion()) {
    settings.theme = next
    return
  }
  const radius = Math.hypot(Math.max(lastTap.x, window.innerWidth - lastTap.x), Math.max(lastTap.y, window.innerHeight - lastTap.y))
  const transition = start.call(document, () => {
    settings.theme = next
  })
  transition.ready.then(() => {
    document.documentElement.animate(
      { clipPath: [`circle(0px at ${lastTap.x}px ${lastTap.y}px)`, `circle(${radius}px at ${lastTap.x}px ${lastTap.y}px)`] },
      { duration: 520, easing: 'cubic-bezier(0.2, 0, 0, 1)', pseudoElement: '::view-transition-new(root)' },
    )
  })
}

onMounted(async () => {
  try {
    await api<{ status: string }>('/health')
    serverOk.value = true
  } catch {
    serverOk.value = false
  }
})
</script>

<template>
  <div class="page">
    <ScreenHeader :title="$t('more.title')" />

    <div v-if="auth.user" class="card flex items-center gap-3.5 px-4 py-4">
      <span class="grid size-12 shrink-0 place-items-center rounded-full bg-money-soft font-display text-lg font-bold text-money">
        {{ auth.user.name.charAt(0).toUpperCase() }}
      </span>
      <div class="flex min-w-0 flex-col gap-0.5">
        <span class="truncate text-[1.0625rem] font-semibold">{{ auth.user.name }}</span>
        <span v-if="auth.user.telegram_username" class="truncate text-caption text-ink-3">@{{ auth.user.telegram_username }}</span>
      </div>
    </div>

    <section class="flex flex-col gap-2.5">
      <h2 class="section-title">{{ $t('more.money') }}</h2>
      <div class="group">
        <UiRow v-for="link in moneyLinks" :key="link.name" :to="{ name: link.name }" :title="$t(link.label)">
          <template #leading>
            <span class="grid size-8 place-items-center rounded-[0.625rem] bg-money-soft text-money">
              <component :is="link.icon" class="size-[18px]" :stroke-width="2" />
            </span>
          </template>
        </UiRow>
      </div>
    </section>

    <section class="flex flex-col gap-2.5">
      <h2 class="section-title">{{ $t('more.language') }}</h2>
      <UiSegmented v-model="settings.locale" :options="locales" :label="$t('more.language')" />
    </section>

    <section class="flex flex-col gap-2.5" @pointerdown.capture="rememberTap">
      <h2 class="section-title">{{ $t('more.theme') }}</h2>
      <UiSegmented :model-value="settings.theme" :options="themeOptions" :label="$t('more.theme')" @update:model-value="setTheme" />
    </section>

    <section v-if="auth.user" class="flex flex-col gap-2.5">
      <h2 class="section-title">{{ $t('more.account') }}</h2>
      <div class="group">
        <UiRow :to="{ name: 'pin-setup', query: { from: 'more' } }" :title="$t('more.changePin')">
          <template #leading><KeyRound class="size-5" /></template>
        </UiRow>
        <UiRow v-if="!confirmingLogout" :title="$t('more.logout')" tone="danger" @click="confirmingLogout = true">
          <template #leading><LogOut class="size-5" /></template>
        </UiRow>
        <div v-else class="flex flex-col gap-3 px-4 py-4">
          <p class="text-sm">{{ $t('more.logoutConfirm') }}</p>
          <div class="flex gap-2">
            <UiButton variant="secondary" size="sm" class="flex-1" @click="confirmingLogout = false">{{ $t('more.cancel') }}</UiButton>
            <UiButton variant="danger" size="sm" class="flex-1" @click="auth.logout()">{{ $t('more.logoutYes') }}</UiButton>
          </div>
        </div>
      </div>
    </section>

    <p v-if="serverOk !== null" class="flex items-center gap-2 px-1 text-caption text-ink-3">
      <span class="size-2 rounded-full" :class="serverOk ? 'bg-money' : 'bg-danger'" />
      {{ $t('more.server') }}: {{ serverOk ? $t('more.serverOk') : $t('more.serverDown') }}
    </p>
  </div>
</template>
