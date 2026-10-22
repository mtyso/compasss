<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ChevronRight, KeyRound, LogOut } from 'lucide-vue-next'
import { api } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useSettings, type Theme } from '@/stores/settings'
import type { Locale } from '@/lib/format'

const settings = useSettings()
const auth = useAuth()
const serverOk = ref<boolean | null>(null)
const confirmingLogout = ref(false)

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

    <section v-if="auth.user" class="flex flex-col gap-2">
      <h2 class="px-1 text-sm font-semibold">{{ $t('more.account') }}</h2>
      <div class="flex flex-col divide-y divide-line overflow-hidden rounded-2xl bg-surface shadow-sm">
        <div class="flex items-center gap-3 px-4 py-3">
          <span class="flex size-10 items-center justify-center rounded-full bg-money-soft font-semibold text-money">
            {{ auth.user.name.charAt(0).toUpperCase() }}
          </span>
          <div class="flex min-w-0 flex-col">
            <span class="truncate font-medium">{{ auth.user.name }}</span>
            <span v-if="auth.user.telegram_username" class="truncate text-sm text-ink-3">@{{ auth.user.telegram_username }}</span>
          </div>
        </div>
        <RouterLink :to="{ name: 'pin-setup', query: { from: 'more' } }" class="flex items-center gap-3 px-4 py-3 active:bg-line">
          <KeyRound class="size-5 text-ink-2" />
          <span class="flex-1">{{ $t('more.changePin') }}</span>
          <ChevronRight class="size-4 text-ink-3" />
        </RouterLink>
        <div class="px-4 py-3">
          <button v-if="!confirmingLogout" type="button" class="flex w-full items-center gap-3 text-danger" @click="confirmingLogout = true">
            <LogOut class="size-5" />
            {{ $t('more.logout') }}
          </button>
          <div v-else class="flex flex-wrap items-center gap-3">
            <span class="flex-1 text-sm">{{ $t('more.logoutConfirm') }}</span>
            <button type="button" class="text-sm text-ink-2" @click="confirmingLogout = false">{{ $t('more.cancel') }}</button>
            <button type="button" class="rounded-xl bg-danger px-3 py-1.5 text-sm font-semibold text-white" @click="auth.logout()">
              {{ $t('more.logoutYes') }}
            </button>
          </div>
        </div>
      </div>
    </section>

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
