import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/lib/api'
import { pushGranted, pushSupported, registerPush, unregisterPush } from '@/lib/push'
import { readJson, writeJson } from '@/lib/storage'
import { useAuth, type PushState } from './auth'

/** This phone's Firebase token while its notifications are on. */
const TOKEN_KEY = 'kompas.pushToken'

/**
 * Notifications on this phone (docs/PLAN.md, D36): on means the server has the phone's token. Where
 * notifications go (`notify_via`: the bot, the phone or both) is a server setting, so the web shows it too.
 */
export const usePush = defineStore('push', () => {
  const auth = useAuth()
  const token = ref<string | null>(readJson<string>(TOKEN_KEY))
  const enabling = ref(false)

  const enabled = computed(() => token.value !== null)

  function keep(next: string | null): void {
    token.value = next
    writeJson(TOKEN_KEY, next)
  }

  function setState(push: PushState): void {
    if (auth.user) auth.setUser({ ...auth.user, push })
  }

  /** Sends the phone's token: on switching on, on every start, and when Firebase rotates it. */
  async function send(next: string): Promise<void> {
    const { push } = await api<{ push: PushState }>('/push/devices', { method: 'POST', body: JSON.stringify({ token: next, platform: 'android' }) })
    keep(next)
    setState(push)
  }

  /** The listener in lib/push.ts: a new token while on (or switching on) goes to the server. */
  function onToken(next: string): void {
    if (!auth.signedIn || (!enabled.value && !enabling.value)) return
    void send(next).catch(() => {
      // The next start sends it again.
    })
  }

  /** Switching on: the system asks to allow notifications, then the phone registers. */
  async function enable(): Promise<void> {
    enabling.value = true
    try {
      await send(await registerPush())
    } finally {
      enabling.value = false
    }
  }

  async function disable(): Promise<void> {
    const current = token.value
    keep(null)
    await unregisterPush()
    if (!current) return
    try {
      const { push } = await api<{ push: PushState }>('/push/devices', { method: 'DELETE', body: JSON.stringify({ token: current }) })
      setState(push)
    } catch {
      // Firebase forgot the token already, so the server drops it on the next push.
    }
  }

  /**
   * On every start once unlocked: re-registering gives the current token, which keeps the phone fresh on
   * the server. Notifications turned off in the phone's settings meanwhile switch it off here too.
   */
  async function refresh(): Promise<void> {
    if (!pushSupported || !enabled.value) return
    if (!(await pushGranted())) {
      await disable()
      return
    }
    try {
      await send(await registerPush())
    } catch {
      // Offline: the token on the server is still the phone's.
    }
  }

  /** Signed out: the server dropped the phone with the sign-in; Firebase forgets it too. */
  function reset(): void {
    if (!token.value) return
    keep(null)
    void unregisterPush()
  }

  async function test(): Promise<void> {
    await api('/push/test', { method: 'POST' })
  }

  return { token, enabled, enabling, onToken, enable, disable, refresh, reset, test }
})
