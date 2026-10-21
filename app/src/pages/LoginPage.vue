<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Send } from 'lucide-vue-next'
import CompassMark from '@/components/ui/CompassMark.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSpinner from '@/components/ui/UiSpinner.vue'
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
  <div class="mx-auto flex min-h-dvh max-w-sm flex-col px-6 pt-[max(2rem,calc(var(--safe-top)+1rem))] pb-[max(1.5rem,var(--safe-bottom))]">
    <!-- The one orchestrated moment: the needle swings in and settles, then the name and the button rise. -->
    <div class="flex flex-1 flex-col justify-center gap-7 py-8">
      <CompassMark :size="112" swing />
      <div class="flex flex-col gap-3">
        <h1 class="rise font-display text-[2.75rem] leading-none font-bold tracking-[-0.04em]" style="--delay: 260ms">{{ $t('appName') }}</h1>
        <p class="rise max-w-64 text-[1.0625rem] text-ink-2" style="--delay: 340ms">{{ $t('login.subtitle') }}</p>
      </div>
    </div>

    <div class="rise flex flex-col gap-3" style="--delay: 440ms">
      <p v-if="auth.signedOutReason" class="rounded-control bg-danger-soft px-4 py-3 text-sm text-danger">
        {{ $t(`login.signedOut.${auth.signedOutReason}`) }}
      </p>

      <Transition name="fade" mode="out-in">
        <div v-if="auth.loginState === 'waiting'" key="waiting" class="card flex flex-col gap-4 p-5">
          <div class="flex items-center gap-3">
            <UiSpinner class="text-money" />
            <p class="font-semibold">{{ $t('login.waiting') }}</p>
          </div>
          <ol class="steps flex flex-col gap-2.5 text-sm text-ink-2">
            <li>{{ $t('login.step1') }}</li>
            <li>{{ $t('login.step2') }}</li>
            <li>{{ $t('login.step3') }}</li>
          </ol>
          <div class="flex flex-col gap-1 pt-1">
            <UiButton variant="telegram" block :href="auth.loginLink ?? undefined" target="_blank" rel="noopener">
              <template #icon><Send class="size-[18px]" /></template>
              {{ $t('login.openTelegram') }}
            </UiButton>
            <UiButton variant="ghost" block @click="auth.cancelLogin()">{{ $t('login.cancel') }}</UiButton>
          </div>
        </div>

        <div v-else key="idle" class="flex flex-col gap-3">
          <p v-if="['denied', 'expired', 'error'].includes(auth.loginState)" class="px-1 text-sm text-danger" role="alert">
            {{ $t(`login.${auth.loginState}`) }}
          </p>
          <UiButton variant="telegram" size="lg" block :loading="auth.loginState === 'starting'" @click="signIn">
            <template #icon><Send class="size-5" /></template>
            {{ $t('login.telegram') }}
          </UiButton>
          <p class="px-1 text-center text-caption text-ink-3">{{ $t('login.hint') }}</p>
        </div>
      </Transition>
    </div>
  </div>
</template>

<style scoped>
.rise {
  animation: rise 560ms var(--ease-enter) both;
  animation-delay: var(--delay, 0ms);
}

@keyframes rise {
  from {
    opacity: 0;
    transform: translateY(14px);
  }
}

/* Numbered steps with the number in a small disc: the order matters here. */
.steps {
  counter-reset: step;
}

.steps li {
  counter-increment: step;
  display: flex;
  gap: 0.75rem;
  align-items: baseline;
}

.steps li::before {
  content: counter(step);
  flex: none;
  display: grid;
  place-items: center;
  width: 1.375rem;
  height: 1.375rem;
  border-radius: 999px;
  background: var(--c-surface-2);
  color: var(--c-ink);
  font-size: 0.75rem;
  font-weight: 600;
  transform: translateY(-1px);
}
</style>
