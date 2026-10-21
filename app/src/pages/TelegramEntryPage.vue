<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Loader2 } from 'lucide-vue-next'
import { telegramApp } from '@/lib/telegram'
import { useAuth } from '@/stores/auth'

// Inside Telegram: signs in with the Mini App launch data, then opens the screen a bot button asked for.
const auth = useAuth()
const router = useRouter()
const route = useRoute()

async function signIn(): Promise<void> {
  if (await auth.signInWithTelegram()) {
    const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/'
    await router.replace(next)
  }
}

onMounted(signIn)
</script>

<template>
  <div class="mx-auto flex min-h-full max-w-sm flex-col items-center justify-center gap-6 px-6 py-10 text-center">
    <h1 class="font-display text-3xl font-bold">{{ $t('appName') }}</h1>

    <template v-if="auth.loginState === 'error'">
      <p class="text-sm text-danger">{{ auth.loginError ?? $t('telegramEntry.failed') }}</p>
      <div class="flex flex-col gap-2 self-stretch">
        <button type="button" class="rounded-2xl bg-ink py-3 font-semibold text-surface active:opacity-80" @click="signIn">
          {{ $t('telegramEntry.retry') }}
        </button>
        <button type="button" class="py-2 text-sm text-ink-2" @click="telegramApp()?.close()">{{ $t('telegramEntry.close') }}</button>
      </div>
    </template>

    <p v-else class="flex items-center gap-2 text-ink-2">
      <Loader2 class="size-5 animate-spin motion-reduce:animate-none" />
      {{ $t('telegramEntry.signingIn') }}
    </p>
  </div>
</template>
