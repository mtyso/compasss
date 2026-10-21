import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ApiError, setApiToken, setUnauthorizedHandler } from '@/lib/api'
import { deviceName } from '@/lib/device'
import { createPinRecord, verifyPin, type PinRecord } from '@/lib/pin'
import { readJson, writeJson } from '@/lib/storage'
import { telegramApp } from '@/lib/telegram'
import type { Locale } from '@/lib/format'

export interface User {
  id: number
  name: string
  telegram_username: string | null
  locale: Locale
  timezone: string
  base_currency: string
  settings: Record<string, unknown>
}

interface LoginStart {
  id: string
  secret: string
  link: string
  expires_at: string
}

type LoginStatus = 'pending' | 'approved' | 'used' | 'denied' | 'expired'

export type LoginState = 'idle' | 'starting' | 'waiting' | 'denied' | 'expired' | 'error'

const TOKEN_KEY = 'kompas.token'
const USER_KEY = 'kompas.user'
const PIN_KEY = 'kompas.pin'
const ATTEMPTS_KEY = 'kompas.pinAttempts'

export const MAX_PIN_ATTEMPTS = 5
const POLL_MS = 2000
/** Leaving the app for longer than this locks it again. */
const LOCK_AFTER_MS = 60_000

export const useAuth = defineStore('auth', () => {
  const token = ref<string | null>(readJson<string>(TOKEN_KEY))
  const user = ref<User | null>(readJson<User>(USER_KEY))
  const pin = ref<PinRecord | null>(readJson<PinRecord>(PIN_KEY))
  const pinAttempts = ref<number>(readJson<number>(ATTEMPTS_KEY) ?? 0)
  // Memory only: every fresh start of the app asks for the PIN. Inside Telegram there is
  // no PIN: Telegram is already open on the owner's phone and vouches for the account.
  const unlocked = ref(telegramApp() !== null)
  /** Why the last session ended, shown once on the sign-in screen. */
  const signedOutReason = ref<'pin' | 'revoked' | null>(null)

  const loginState = ref<LoginState>('idle')
  const loginLink = ref<string | null>(null)
  /** The server's reason when the Mini App sign-in was refused, e.g. a foreign account. */
  const loginError = ref<string | null>(null)
  let pending: (LoginStart & { timer: number | null }) | null = null

  setApiToken(token.value)
  setUnauthorizedHandler(() => forget('revoked'))

  const signedIn = computed(() => token.value !== null)
  const pinAttemptsLeft = computed(() => MAX_PIN_ATTEMPTS - pinAttempts.value)

  function saveSession(newToken: string, newUser: User): void {
    token.value = newToken
    user.value = newUser
    setApiToken(newToken)
    writeJson(TOKEN_KEY, newToken)
    writeJson(USER_KEY, newUser)
  }

  function setUser(next: User): void {
    user.value = next
    writeJson(USER_KEY, next)
  }

  /** Drops everything this device knows about the session, without calling the server. */
  function forget(reason: 'pin' | 'revoked' | null = null): void {
    cancelLogin()
    token.value = null
    user.value = null
    pin.value = null
    unlocked.value = false
    signedOutReason.value = reason
    setApiToken(null)
    writeJson(TOKEN_KEY, null)
    writeJson(USER_KEY, null)
    writeJson(PIN_KEY, null)
    setPinAttempts(0)
  }

  async function logout(reason: 'pin' | null = null): Promise<void> {
    try {
      await api('/auth/logout', { method: 'POST' })
    } catch {
      // Signing out locally matters more than the server call succeeding.
    }
    forget(reason)
  }

  // --- Sign-in through the bot (docs/PLAN.md, section 6) ---

  async function startLogin(): Promise<void> {
    cancelLogin()
    signedOutReason.value = null
    loginState.value = 'starting'
    try {
      const start = await api<LoginStart>('/auth/telegram/start', {
        method: 'POST',
        body: JSON.stringify({ device_name: deviceName() }),
      })
      pending = { ...start, timer: null }
      loginLink.value = start.link
      loginState.value = 'waiting'
      schedulePoll()
    } catch {
      loginState.value = 'error'
    }
  }

  function schedulePoll(delay = POLL_MS): void {
    if (!pending) return
    if (pending.timer !== null) clearTimeout(pending.timer)
    pending.timer = window.setTimeout(pollLogin, delay)
  }

  /** Asks the server once; the sign-in screen also calls it when the user comes back from Telegram. */
  async function pollLogin(): Promise<void> {
    const current = pending
    if (!current) return

    let result: { status: LoginStatus; token?: string; user?: User }
    try {
      result = await api(`/auth/telegram/status/${current.id}`, { headers: { 'X-Login-Secret': current.secret } })
    } catch {
      // A dropped connection while switching apps is normal: try again until the link expires.
      if (new Date(current.expires_at).getTime() < Date.now()) {
        finishLogin('expired')
      } else {
        schedulePoll()
      }
      return
    }
    if (pending !== current) return

    if (result.status === 'approved' && result.token && result.user) {
      saveSession(result.token, result.user)
      finishLogin('idle')
    } else if (result.status === 'pending') {
      schedulePoll()
    } else {
      finishLogin(result.status === 'denied' ? 'denied' : 'expired')
    }
  }

  function finishLogin(state: LoginState): void {
    cancelLogin()
    loginState.value = state
  }

  /** Inside the Telegram Mini App: trade Telegram's signed launch data for a token. */
  async function signInWithTelegram(): Promise<boolean> {
    const app = telegramApp()
    if (!app) return false

    signedOutReason.value = null
    loginState.value = 'starting'
    loginError.value = null
    try {
      const result = await api<{ token: string; user: User }>('/auth/telegram/webapp', {
        method: 'POST',
        body: JSON.stringify({ init_data: app.initData, platform: app.platform }),
      })
      saveSession(result.token, result.user)
      unlocked.value = true
      loginState.value = 'idle'
      return true
    } catch (error) {
      loginError.value = error instanceof ApiError && error.status < 500 ? error.message : null
      loginState.value = 'error'
      return false
    }
  }

  function cancelLogin(): void {
    if (pending?.timer != null) clearTimeout(pending.timer)
    pending = null
    loginLink.value = null
    loginState.value = 'idle'
  }

  // --- PIN lock ---

  function setPinAttempts(value: number): void {
    pinAttempts.value = value
    writeJson(ATTEMPTS_KEY, value || null)
  }

  async function setPin(value: string): Promise<void> {
    pin.value = await createPinRecord(value)
    writeJson(PIN_KEY, pin.value)
    setPinAttempts(0)
    unlocked.value = true
  }

  /** @returns true when the PIN is right; after too many misses the device is signed out. */
  async function unlock(value: string): Promise<boolean> {
    if (!pin.value) return false
    if (await verifyPin(value, pin.value)) {
      setPinAttempts(0)
      unlocked.value = true
      return true
    }
    setPinAttempts(pinAttempts.value + 1)
    if (pinAttempts.value >= MAX_PIN_ATTEMPTS) {
      await logout('pin')
    }
    return false
  }

  let hiddenAt: number | null = null
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      hiddenAt = Date.now()
    } else if (hiddenAt !== null && Date.now() - hiddenAt > LOCK_AFTER_MS && pin.value && !telegramApp()) {
      unlocked.value = false
    }
  })

  return {
    token,
    user,
    pin,
    unlocked,
    signedIn,
    signedOutReason,
    pinAttemptsLeft,
    loginState,
    loginLink,
    loginError,
    setUser,
    logout,
    forget,
    startLogin,
    signInWithTelegram,
    pollLogin,
    cancelLogin,
    setPin,
    unlock,
  }
})
