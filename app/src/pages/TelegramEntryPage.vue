<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CompassMark from '@/components/ui/CompassMark.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSpinner from '@/components/ui/UiSpinner.vue'
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
  <div class="mx-auto flex min-h-dvh max-w-sm flex-col px-6 pt-[max(2rem,calc(var(--safe-top)+1rem))] pb-[max(1.5rem,var(--safe-bottom))]">
    <div class="flex flex-1 flex-col items-center justify-center gap-6 text-center">
      <CompassMark :size="72" swing />
      <h1 class="font-display text-[2rem] font-bold tracking-[-0.03em]">{{ $t('appName') }}</h1>

      <Transition name="fade" mode="out-in">
        <div v-if="auth.loginState === 'error'" key="error" class="flex flex-col gap-4 self-stretch">
          <p class="text-sm text-balance text-danger" role="alert">{{ auth.loginError ?? $t('telegramEntry.failed') }}</p>
          <div class="flex flex-col gap-1">
            <UiButton block @click="signIn">{{ $t('telegramEntry.retry') }}</UiButton>
            <UiButton variant="ghost" block @click="telegramApp()?.close()">{{ $t('telegramEntry.close') }}</UiButton>
          </div>
        </div>

        <p v-else key="waiting" class="flex items-center gap-2 text-ink-2">
          <UiSpinner class="text-money" />
          {{ $t('telegramEntry.signingIn') }}
        </p>
      </Transition>
    </div>
  </div>
</template>
