<script setup lang="ts">
import { watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/lib/api'
import { useAuth, type User } from '@/stores/auth'
import { useMoney } from '@/stores/money'
import { useSettings } from '@/stores/settings'

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
</script>

<template>
  <RouterView />
</template>
