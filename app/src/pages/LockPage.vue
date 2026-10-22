<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import PinPad from '@/components/PinPad.vue'
import CompassMark from '@/components/ui/CompassMark.vue'
import UiButton from '@/components/ui/UiButton.vue'
import { biometricAvailable } from '@/lib/biometric'
import { MAX_PIN_ATTEMPTS, useAuth } from '@/stores/auth'

const auth = useAuth()
const router = useRouter()
const route = useRoute()
const { t } = useI18n()

const errorKey = ref(0)
const busy = ref(false)
const success = ref(false)
const mark = ref<InstanceType<typeof CompassMark> | null>(null)
const canBiometric = ref(false)

async function open(): Promise<void> {
  // A beat of green dots before the app opens, so a right PIN feels confirmed.
  success.value = true
  await new Promise((resolve) => window.setTimeout(resolve, 260))
  const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/'
  await router.replace(next)
}

async function onComplete(pin: string): Promise<void> {
  busy.value = true
  const ok = await auth.unlock(pin)
  busy.value = false
  if (ok) {
    await open()
  } else {
    errorKey.value++
    mark.value?.swingFrom(30)
  }
}

async function tryBiometric(): Promise<void> {
  if (busy.value || success.value) return
  busy.value = true
  const ok = await auth.unlockWithBiometric({ title: t('pin.biometricTitle'), subtitle: t('pin.biometricSubtitle'), cancel: t('pin.biometricCancel') })
  busy.value = false
  if (ok) await open()
}

// With fingerprint unlock on, the system prompt comes up by itself; «Ввести PIN» closes it.
onMounted(async () => {
  canBiometric.value = auth.biometric && (await biometricAvailable())
  if (canBiometric.value) await tryBiometric()
})
</script>

<template>
  <div class="mx-auto flex min-h-dvh max-w-sm flex-col items-center px-6 pt-[max(2.5rem,calc(var(--safe-top)+1.5rem))] pb-[max(1.5rem,var(--safe-bottom))] text-center">
    <div class="flex flex-1 flex-col items-center justify-center gap-5 pb-8">
      <CompassMark ref="mark" :size="56" swing />
      <div class="flex flex-col gap-1.5">
        <h1 class="text-headline font-semibold">{{ $t('pin.enter') }}</h1>
        <p class="min-h-5 text-sm" :class="auth.pinAttemptsLeft < MAX_PIN_ATTEMPTS ? 'text-danger' : 'text-ink-2'">
          <template v-if="auth.pinAttemptsLeft < MAX_PIN_ATTEMPTS">
            {{ $t('pin.wrong', { n: auth.pinAttemptsLeft }) }}
          </template>
          <template v-else-if="auth.user?.name">{{ $t('pin.hello', { name: auth.user.name }) }}</template>
        </p>
      </div>
    </div>
    <PinPad :error-key="errorKey" :busy="busy" :success="success" :biometric="canBiometric" @complete="onComplete" @biometric="tryBiometric" />
    <UiButton variant="ghost" size="sm" class="mt-6" @click="auth.logout()">{{ $t('pin.forgot') }}</UiButton>
  </div>
</template>
