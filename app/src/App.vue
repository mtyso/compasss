<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/lib/api'
import { useAdvisor } from '@/stores/advisor'
import { useAuth, type User } from '@/stores/auth'
import { useCalendar } from '@/stores/calendar'
import { useDebts } from '@/stores/debts'
import { useGoals } from '@/stores/goals'
import { useHabits } from '@/stores/habits'
import { useMoney } from '@/stores/money'
import { usePlan } from '@/stores/plan'
import { useSettings } from '@/stores/settings'
import { useTasks } from '@/stores/tasks'

const auth = useAuth()
const settings = useSettings()
const router = useRouter()

// Signing out, a revoked token or the app locking itself while open. Moving forward
// (signed in, unlocked) is left to the screen that did it, which knows where to go next.
watch(
  () => auth.signedIn,
  (signedIn) => {
    if (!signedIn) {
      useMoney().reset()
      usePlan().reset()
      useAdvisor().reset()
      useTasks().reset()
      useHabits().reset()
      useGoals().reset()
      useDebts().reset()
      useCalendar().reset()
      router.replace({ name: 'login' })
    }
  },
)
watch(
  () => auth.unlocked,
  (unlocked) => {
    const current = router.currentRoute.value
    if (!unlocked && auth.signedIn && auth.pin && !current.meta.gate) {
      router.replace({ name: 'lock', query: { next: current.fullPath } })
    }
  },
)

// The bot writes in the account's language, so keep it in step with the app's.
watch(
  () => [auth.user?.locale, settings.locale] as const,
  async ([serverLocale, appLocale]) => {
    if (!serverLocale || serverLocale === appLocale) return
    try {
      auth.setUser(await api<User>('/me', { method: 'PATCH', body: JSON.stringify({ locale: appLocale }) }))
    } catch {
      // Retried on the next change or sign-in.
    }
  },
  { immediate: true },
)

// The browser's bar follows the app's theme, not just the system's: when you pick Тёмная on a light
// phone, the bar goes dark too.
function syncThemeColor(): void {
  const color = getComputedStyle(document.documentElement).getPropertyValue('--c-bg').trim()
  if (!color) return
  for (const meta of document.querySelectorAll('meta[name="theme-color"]')) meta.setAttribute('content', color)
}
const themeObserver = new MutationObserver(syncThemeColor)
themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
syncThemeColor()
onBeforeUnmount(() => themeObserver.disconnect())
</script>

<template>
  <!-- Sign-in, PIN and the app itself cross-fade; screens inside the app animate in AppShell. -->
  <RouterView v-slot="{ Component, route }">
    <Transition name="fade" mode="out-in">
      <component :is="Component" :key="route.matched[0]?.path" />
    </Transition>
  </RouterView>
</template>
