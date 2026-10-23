import { PushNotifications } from '@capacitor/push-notifications'
import { isNative } from './native'

/**
 * Push notifications in the Android app (docs/PLAN.md, D36), through Firebase. The plugin needs the
 * Firebase config built into the APK (CI writes google-services.json when the secret is set, and sets
 * VITE_PUSH_ENABLED): without it, touching the plugin crashes the app, so everything here checks this first.
 */
export const pushSupported = isNative && import.meta.env.VITE_PUSH_ENABLED === 'true'

/** The Android channel the server's messages name (server config/firebase.php). */
export const PUSH_CHANNEL = 'kompas'

export class PushDenied extends Error {}

type Waiter = { resolve: (token: string) => void; reject: (error: Error) => void }
let waiter: Waiter | null = null
let onToken: ((token: string) => void) | null = null

/**
 * A path a notification may open: one of the app's own, never another site. The server sends the same
 * paths as the bot's buttons («/advice», «/money/report/2026-10»).
 */
export function safePath(url: unknown): string | null {
  return typeof url === 'string' && url.startsWith('/') && !url.startsWith('//') && !url.includes('\\') ? url : null
}

/**
 * Listens for the phone's token (on registering, and whenever Firebase rotates it) and for taps. Called
 * once at start: a tap that opened the app waits in the plugin until this listener is there.
 */
export async function setupPush(handlers: { token: (token: string) => void; open: (path: string) => void }): Promise<void> {
  if (!pushSupported) return
  onToken = handlers.token
  try {
    await PushNotifications.addListener('registration', ({ value }) => {
      // Asked for by registerPush(), whose caller sends it; otherwise Firebase rotated it on its own.
      if (waiter) waiter.resolve(value)
      else onToken?.(value)
      waiter = null
    })
    await PushNotifications.addListener('registrationError', ({ error }) => {
      waiter?.reject(new Error(error))
      waiter = null
    })
    await PushNotifications.addListener('pushNotificationActionPerformed', ({ notification }) => {
      const path = safePath(notification.data?.url)
      if (path) handlers.open(path)
    })
  } catch {
    // A phone without Google services: the app works on, without push.
  }
  try {
    // After the listeners: Android 7 has no channels and refuses this, and the token and taps must still work.
    await PushNotifications.createChannel({ id: PUSH_CHANNEL, name: 'Компас', importance: 4, visibility: 1 })
  } catch {
    // Firebase falls back to the manifest's default channel.
  }
}

/** Notifications are allowed already, so the token can be refreshed without asking. */
export async function pushGranted(): Promise<boolean> {
  if (!pushSupported) return false
  try {
    return (await PushNotifications.checkPermissions()).receive === 'granted'
  } catch {
    return false
  }
}

/**
 * Asks to allow notifications if need be, then registers with Firebase: resolves with the phone's token.
 * PushDenied when the user said no (then only the phone's settings can turn them on).
 */
export async function registerPush(): Promise<string> {
  if (!pushSupported) throw new Error('push unsupported')
  let { receive } = await PushNotifications.checkPermissions()
  if (receive === 'prompt' || receive === 'prompt-with-rationale') receive = (await PushNotifications.requestPermissions()).receive
  if (receive !== 'granted') throw new PushDenied()

  const token = new Promise<string>((resolve, reject) => {
    waiter = { resolve, reject }
    window.setTimeout(() => {
      if (waiter?.resolve !== resolve) return
      waiter = null
      reject(new Error('push registration timed out'))
    }, 20_000)
  })
  await PushNotifications.register()
  return token
}

/** Stops this phone's notifications: Firebase forgets the token. */
export async function unregisterPush(): Promise<void> {
  if (!pushSupported) return
  try {
    await PushNotifications.unregister()
  } catch {
    // Already gone.
  }
}
