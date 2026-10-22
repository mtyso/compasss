// Haptic feedback with one small vocabulary, wherever the app runs:
// - Android app: Capacitor's Haptics plugin, when it is installed (looked up at runtime, no import);
// - Telegram mini app: Telegram.WebApp.HapticFeedback;
// - mobile browser: navigator.vibrate, where the browser allows it.
// Every call is fire-and-forget and silently does nothing where none of these exist.

type Impact = 'light' | 'medium' | 'heavy'
type Notice = 'success' | 'warning' | 'error'

interface CapacitorHaptics {
  impact(options: { style: 'LIGHT' | 'MEDIUM' | 'HEAVY' }): Promise<void>
  notification(options: { type: 'SUCCESS' | 'WARNING' | 'ERROR' }): Promise<void>
  selectionChanged?(): Promise<void>
}

interface TelegramHaptics {
  impactOccurred(style: Impact): void
  notificationOccurred(type: Notice): void
  selectionChanged(): void
}

declare global {
  interface Window {
    Capacitor?: { isNativePlatform?: () => boolean; Plugins?: { Haptics?: CapacitorHaptics } }
    Telegram?: { WebApp?: { HapticFeedback?: TelegramHaptics } }
  }
}

function capacitor(): CapacitorHaptics | undefined {
  return window.Capacitor?.isNativePlatform?.() ? window.Capacitor.Plugins?.Haptics : undefined
}

function telegram(): TelegramHaptics | undefined {
  return window.Telegram?.WebApp?.HapticFeedback
}

function vibrate(pattern: number | number[]): void {
  try {
    navigator.vibrate?.(pattern)
  } catch {
    // Not allowed here (desktop, no user gesture yet).
  }
}

const VIBRATION: Record<Impact | Notice | 'selection', number | number[]> = {
  selection: 4,
  light: 8,
  medium: 14,
  heavy: 22,
  success: [10, 60, 14],
  warning: [16, 80, 16],
  error: [20, 50, 20, 50, 20],
}

export const haptics = {
  /** Picking one option among several: a tab, a category, a segment. */
  selection(): void {
    const native = capacitor()
    if (native) return void native.selectionChanged?.().catch(() => {})
    const tg = telegram()
    if (tg) return tg.selectionChanged()
    vibrate(VIBRATION.selection)
  },
  /** A physical-feeling tap: the + button, a PIN key, a sheet snapping shut. */
  impact(style: Impact = 'light'): void {
    const native = capacitor()
    if (native) return void native.impact({ style: style.toUpperCase() as 'LIGHT' }).catch(() => {})
    const tg = telegram()
    if (tg) return tg.impactOccurred(style)
    vibrate(VIBRATION[style])
  },
  /** The outcome of an action: saved, over the limit, wrong PIN. */
  notify(type: Notice): void {
    const native = capacitor()
    if (native) return void native.notification({ type: type.toUpperCase() as 'SUCCESS' }).catch(() => {})
    const tg = telegram()
    if (tg) return tg.notificationOccurred(type)
    vibrate(VIBRATION[type])
  },
}

/** Flat form of the same calls, for code that prefers one function. */
export function haptic(kind: Impact | Notice | 'select'): void {
  if (kind === 'select') haptics.selection()
  else if (kind === 'success' || kind === 'warning' || kind === 'error') haptics.notify(kind)
  else haptics.impact(kind)
}
