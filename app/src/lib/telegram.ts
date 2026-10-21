// The same SPA runs as a Telegram Mini App (docs/BOT.md). Inside Telegram the app:
//  - signs in with the launch data Telegram signs for us, without the bot round trip or a PIN;
//  - paints Telegram's header, background and bottom bar in our own colours;
//  - uses Telegram's «Back» button on nested screens and turns off swipe-to-close,
//    so swiping a sheet down does not fold the whole app.
// Outside Telegram (browser, Android app) nothing here runs and the SDK is never loaded.

import type { Router } from 'vue-router'

/** The parts of window.Telegram.WebApp the app uses (https://core.telegram.org/bots/webapps). */
export interface TelegramWebApp {
  initData: string
  initDataUnsafe: { user?: { id: number; first_name: string; language_code?: string }; start_param?: string }
  version: string
  platform: string
  colorScheme: 'light' | 'dark'
  isVersionAtLeast(version: string): boolean
  ready(): void
  expand(): void
  close(): void
  disableVerticalSwipes(): void
  setHeaderColor(color: string): void
  setBackgroundColor(color: string): void
  setBottomBarColor(color: string): void
  onEvent(event: string, handler: () => void): void
  offEvent(event: string, handler: () => void): void
  BackButton: { isVisible: boolean; show(): void; hide(): void; onClick(handler: () => void): void }
}

declare global {
  interface Window {
    Telegram?: { WebApp?: TelegramWebApp }
  }
}

const SDK_URL = 'https://telegram.org/js/telegram-web-app.js?64'
const SDK_TIMEOUT_MS = 8000

let webApp: TelegramWebApp | null = null

/**
 * Telegram opens a Mini App with its launch parameters in the URL hash (#tgWebAppData=…);
 * the SDK keeps them in sessionStorage, so a reload inside Telegram still counts.
 */
export function launchedFromTelegram(hash: string = window.location.hash, storage: Pick<Storage, 'getItem'> | null = safeSessionStorage()): boolean {
  if (/[#&]tgWebApp(Data|Platform|Version)=/.test(hash)) return true
  try {
    return Boolean(storage?.getItem('__telegram__initParams'))
  } catch {
    return false
  }
}

function safeSessionStorage(): Storage | null {
  try {
    return window.sessionStorage
  } catch {
    return null
  }
}

/** The Mini App, or null in a browser or the Android app. Ready once initTelegram() resolved. */
export function telegramApp(): TelegramWebApp | null {
  return webApp
}

export function inTelegram(): boolean {
  return webApp !== null
}

/** For tests. */
export function setTelegramApp(app: TelegramWebApp | null): void {
  webApp = app
}

/**
 * Loads the SDK and prepares the window. Must finish before the router starts: the SDK reads
 * its launch data from the URL hash, and the first redirect would drop it.
 */
export async function initTelegram(): Promise<TelegramWebApp | null> {
  if (!launchedFromTelegram()) return null

  try {
    await loadScript(SDK_URL, SDK_TIMEOUT_MS)
  } catch {
    // No SDK (blocked, offline): carry on as a plain website with the usual sign-in.
    return null
  }

  const app = window.Telegram?.WebApp
  if (!app?.initData) return null
  webApp = app

  document.documentElement.classList.add('in-telegram')
  app.ready()
  app.expand()
  if (app.isVersionAtLeast('7.7')) app.disableVerticalSwipes()

  syncColors()
  // The theme class on <html> changes with the app's own setting and with Telegram's theme.
  new MutationObserver(syncColors).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })

  return app
}

function loadScript(src: string, timeoutMs: number): Promise<void> {
  return new Promise((resolve, reject) => {
    const script = document.createElement('script')
    const timer = window.setTimeout(() => reject(new Error('timeout')), timeoutMs)
    script.src = src
    script.onload = () => {
      window.clearTimeout(timer)
      resolve()
    }
    script.onerror = () => {
      window.clearTimeout(timer)
      reject(new Error('failed'))
    }
    document.head.appendChild(script)
  })
}

/** Telegram's header and background take the page colour, its bottom bar the tab bar's. */
export function syncColors(): void {
  if (!webApp) return
  const styles = getComputedStyle(document.documentElement)
  const background = toHex(styles.getPropertyValue('--c-bg')) ?? toHex(getComputedStyle(document.body).backgroundColor)
  const surface = toHex(styles.getPropertyValue('--c-surface')) ?? background

  if (background) {
    webApp.setHeaderColor(background)
    webApp.setBackgroundColor(background)
  }
  if (surface && webApp.isVersionAtLeast('7.10')) {
    webApp.setBottomBarColor(surface)
  }
}

/** Telegram takes only #RRGGBB. Accepts that, #RGB and rgb()/rgba(); anything else goes through a canvas pixel. */
export function toHex(color: string): string | null {
  const value = color.trim().toLowerCase()
  if (/^#[0-9a-f]{6}$/.test(value)) return value
  if (/^#[0-9a-f]{3}$/.test(value)) return '#' + [...value.slice(1)].map((c) => c + c).join('')

  const rgb = value.match(/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/)
  if (rgb) return '#' + rgb.slice(1, 4).map((n) => Number(n).toString(16).padStart(2, '0')).join('')

  if (!value) return null
  try {
    const canvas = document.createElement('canvas')
    canvas.width = canvas.height = 1
    const context = canvas.getContext('2d')
    if (!context) return null
    context.fillStyle = value
    context.fillRect(0, 0, 1, 1)
    const [r, g, b] = context.getImageData(0, 0, 1, 1).data
    return '#' + [r, g, b].map((n) => n.toString(16).padStart(2, '0')).join('')
  } catch {
    return null
  }
}

/** Telegram's own dark or light, for the «как в системе» theme setting. Null outside Telegram. */
export function telegramPrefersDark(): boolean | null {
  return webApp ? webApp.colorScheme === 'dark' : null
}

export function onTelegramThemeChange(handler: () => void): void {
  webApp?.onEvent('themeChanged', handler)
}

/** Screens below a tab (/more/accounts, /more/budget …) get Telegram's «Back» button. */
export function isNestedPath(path: string): boolean {
  return path.split('/').filter(Boolean).length > 1
}

export function bindBackButton(router: Router): void {
  const app = webApp
  if (!app) return

  app.BackButton.onClick(() => {
    // Opened straight on a nested screen from a bot button: there is no history to go back to.
    if (window.history.state?.back) router.back()
    else router.replace('/' + router.currentRoute.value.path.split('/').filter(Boolean)[0])
  })
  router.afterEach((to) => {
    if (isNestedPath(to.path)) app.BackButton.show()
    else app.BackButton.hide()
  })
}
