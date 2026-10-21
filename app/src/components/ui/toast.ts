import { reactive } from 'vue'

// Short messages above the tab bar: "Записано", "Операция удалена · Вернуть". One at a time; a new
// toast replaces the current one. They never block the screen and never need dismissing.

export interface ToastOptions {
  message: string
  /** Second line, quieter: the amount, what is left for today. */
  detail?: string
  tone?: 'default' | 'success' | 'warning' | 'danger'
  action?: { label: string; run: () => void | Promise<void> }
  /** ms; toasts with an action stay a little longer by default. */
  duration?: number
}

export interface Toast extends ToastOptions {
  id: number
}

export const toastState = reactive<{ current: Toast | null }>({ current: null })

let nextId = 1
let timer: number | undefined

export function showToast(options: ToastOptions): number {
  window.clearTimeout(timer)
  const id = nextId++
  toastState.current = { ...options, id }
  timer = window.setTimeout(() => dismissToast(id), options.duration ?? (options.action ? 5000 : 2800))
  return id
}

export function dismissToast(id?: number): void {
  if (id !== undefined && toastState.current?.id !== id) return
  window.clearTimeout(timer)
  toastState.current = null
}

/** Keeps the toast on screen while a finger is on it. */
export function holdToast(): void {
  window.clearTimeout(timer)
}

export function releaseToast(): void {
  const id = toastState.current?.id
  if (id !== undefined) timer = window.setTimeout(() => dismissToast(id), 2000)
}
