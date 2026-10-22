<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Loader2, Send } from 'lucide-vue-next'
import { useAuth } from '@/stores/auth'

const auth = useAuth()
const router = useRouter()

// Confirmed in the bot: the route guard sends a new device on to choosing a PIN.
watch(
  () => auth.signedIn,
  (signedIn) => {
    if (signedIn) router.replace('/')
  },
)

async function signIn(): Promise<void> {
  await auth.startLogin()
  if (auth.loginLink) {
    // Opening right after the tap usually passes the popup blocker; if not, the button below does it.
    window.open(auth.loginLink, '_blank', 'noopener')
  }
}

// Coming back from Telegram: check straight away instead of waiting for the next poll.
function onVisible(): void {
  if (document.visibilityState === 'visible' && auth.loginState === 'waiting') {
    auth.pollLogin()
  }
}
onMounted(() => document.addEventListener('visibilitychange', onVisible))
onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', onVisible)
  auth.cancelLogin()
})
</script>

<template>
  <div class="mx-auto flex min-h-full max-w-sm flex-col justify-center gap-8 px-6 py-10">
    <div class="flex flex-col gap-2">
      <h1 class="font-display text-4xl font-bold">{{ $t('appName') }}</h1>
      <p class="text-ink-2">{{ $t('login.subtitle') }}</p>
    </div>

    <p v-if="auth.signedOutReason" class="rounded-2xl bg-danger-soft px-4 py-3 text-sm text-danger">
      {{ $t(`login.signedOut.${auth.signedOutReason}`) }}
    </p>

    <Transition name="fade" mode="out-in">
      <div v-if="auth.loginState === 'waiting'" key="waiting" class="flex flex-col gap-5 rounded-3xl bg-surface p-5 shadow-sm">
        <div class="flex items-center gap-3">
          <Loader2 class="size-5 shrink-0 animate-spin text-ink-3 motion-reduce:animate-none" />
          <p class="font-semibold">{{ $t('login.waiting') }}</p>
        </div>
        <ol class="flex list-decimal flex-col gap-1.5 pl-5 text-sm text-ink-2">
          <li>{{ $t('login.step1') }}</li>
          <li>{{ $t('login.step2') }}</li>
          <li>{{ $t('login.step3') }}</li>
        </ol>
        <div class="flex flex-col gap-2">
          <a
            :href="auth.loginLink ?? undefined"
            target="_blank"
            rel="noopener"
            class="flex items-center justify-center gap-2 rounded-2xl bg-[#229ED9] py-3 font-semibold text-white active:opacity-80"
          >
            <Send class="size-4" />
            {{ $t('login.openTelegram') }}
          </a>
          <button type="button" class="py-2 text-sm text-ink-2" @click="auth.cancelLogin()">{{ $t('login.cancel') }}</button>
        </div>
      </div>

      <div v-else key="idle" class="flex flex-col gap-3">
        <p v-if="['denied', 'expired', 'error'].includes(auth.loginState)" class="px-1 text-sm text-danger">
          {{ $t(`login.${auth.loginState}`) }}
        </p>
        <button
          type="button"
          class="flex items-center justify-center gap-2 rounded-2xl bg-[#229ED9] py-3.5 font-semibold text-white transition-opacity active:opacity-80 disabled:opacity-60"
          :disabled="auth.loginState === 'starting'"
          @click="signIn"
        >
          <Loader2 v-if="auth.loginState === 'starting'" class="size-5 animate-spin motion-reduce:animate-none" />
          <Send v-else class="size-5" />
          {{ $t('login.telegram') }}
        </button>
        <p class="px-1 text-xs text-ink-3">{{ $t('login.hint') }}</p>
      </div>
    </Transition>
  </div>
</template>
