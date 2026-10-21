import { App } from '@capacitor/app'
import { Capacitor } from '@capacitor/core'

/** True inside the Android app (iOS later), false in the browser and inside Telegram. */
export const isNative = Capacitor.isNativePlatform()

/**
 * Opens a link outside the app. In the browser that is a new tab. In the Android app the WebView
 * hands any other host to the system, so t.me opens Telegram itself.
 */
export function openExternal(url: string): void {
  if (isNative) window.location.href = url
  else window.open(url, '_blank', 'noopener')
}

/**
 * The Android back button: it closes the open sheet first, then goes back a screen, and on the
 * first screen sends the app to the background instead of closing it.
 */
export function setupNative(): void {
  if (!isNative) return
  void App.addListener('backButton', ({ canGoBack }) => {
    const sheets = document.querySelectorAll<HTMLElement>('[role="dialog"][aria-modal="true"]')
    const top = sheets[sheets.length - 1]
    if (top) top.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    else if (canGoBack) window.history.back()
    else void App.minimizeApp()
  })
}
