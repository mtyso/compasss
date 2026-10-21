import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { i18n } from '@/i18n'
import { setApiLocale } from '@/lib/api'
import type { Locale } from '@/lib/format'

export type Theme = 'system' | 'light' | 'dark'

const STORAGE_KEY = 'kompas.settings'

function load(): { locale?: Locale; theme?: Theme } {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}')
  } catch {
    return {}
  }
}

export const useSettings = defineStore('settings', () => {
  const saved = load()
  const locale = ref<Locale>(saved.locale ?? 'ru')
  const theme = ref<Theme>(saved.theme ?? 'system')

  const media = window.matchMedia('(prefers-color-scheme: dark)')

  function applyTheme(): void {
    const dark = theme.value === 'dark' || (theme.value === 'system' && media.matches)
    document.documentElement.classList.toggle('dark', dark)
  }

  function applyLocale(): void {
    i18n.global.locale.value = locale.value
    document.documentElement.lang = locale.value
    setApiLocale(locale.value)
  }

  media.addEventListener('change', applyTheme)

  watch(
    [locale, theme],
    () => {
      applyTheme()
      applyLocale()
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ locale: locale.value, theme: theme.value }))
      } catch {
        // Storage can be unavailable (private mode); settings then live for this session only.
      }
    },
    { immediate: true },
  )

  return { locale, theme }
})
