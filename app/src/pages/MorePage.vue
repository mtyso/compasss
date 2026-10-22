<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/lib/api'
import { useSettings, type Theme } from '@/stores/settings'
import type { Locale } from '@/lib/format'

const settings = useSettings()
const serverOk = ref<boolean | null>(null)

const locales: { value: Locale; label: string }[] = [
  { value: 'ru', label: 'Русский' },
  { value: 'uz', label: "O'zbekcha" },
]
const themes: Theme[] = ['system', 'light', 'dark']

onMounted(async () => {
  try {
    await api<{ status: string }>('/health')
    serverOk.value = true
  } catch {
    serverOk.value = false
  }
})
</script>

<template>
  <div class="flex flex-col gap-5">
    <h1 class="px-1 font-display text-2xl font-bold">{{ $t('more.title') }}</h1>

    <section class="flex flex-col gap-2">
      <h2 class="px-1 text-sm font-semibold">{{ $t('more.language') }}</h2>
      <div class="grid grid-cols-2 gap-1 rounded-2xl bg-line p-1">
        <button
          v-for="item in locales"
          :key="item.value"
          type="button"
          class="rounded-xl py-2 text-sm font-medium"
          :class="settings.locale === item.value ? 'bg-surface text-ink shadow-sm' : 'text-ink-2'"
          @click="settings.locale = item.value"
        >
          {{ item.label }}
        </button>
      </div>
    </section>

    <section class="flex flex-col gap-2">
      <h2 class="px-1 text-sm font-semibold">{{ $t('more.theme') }}</h2>
      <div class="grid grid-cols-3 gap-1 rounded-2xl bg-line p-1">
        <button
          v-for="item in themes"
          :key="item"
          type="button"
          class="rounded-xl py-2 text-sm font-medium"
          :class="settings.theme === item ? 'bg-surface text-ink shadow-sm' : 'text-ink-2'"
          @click="settings.theme = item"
        >
          {{ $t(`more.themes.${item}`) }}
        </button>
      </div>
    </section>

    <p v-if="serverOk !== null" class="px-1 text-xs text-ink-3">
      {{ $t('more.server') }}:
      <span :class="serverOk ? 'text-money' : 'text-danger'">{{ serverOk ? $t('more.serverOk') : $t('more.serverDown') }}</span>
    </p>
  </div>
</template>
