<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { FingerprintPattern } from 'lucide-vue-next'
import { useRoute, useRouter } from 'vue-router'
import PinPad from '@/components/PinPad.vue'
import CompassMark from '@/components/ui/CompassMark.vue'
import UiButton from '@/components/ui/UiButton.vue'
import { biometricAvailable, confirmBiometric } from '@/lib/biometric'
import { useAuth } from '@/stores/auth'

const auth = useAuth()
const router = useRouter()
const route = useRoute()
const { t } = useI18n()

const first = ref<string | null>(null)
const errorKey = ref(0)
const mismatch = ref(false)
const busy = ref(false)
const success = ref(false)
const pad = ref<InstanceType<typeof PinPad> | null>(null)
/** After the first PIN on a phone with a fingerprint: offer to unlock with it. */
const offerBiometric = ref(false)

async function onComplete(pin: string): Promise<void> {
  if (first.value === null) {
    first.value = pin
    mismatch.value = false
    // Let the fourth dot land before the pad clears for the repeat.
    window.setTimeout(() => pad.value?.reset(), 160)
    return
  }
  if (pin !== first.value) {
    first.value = null
    mismatch.value = true
    errorKey.value++
    return
  }
  busy.value = true
  await auth.setPin(pin)
  success.value = true
  await new Promise((resolve) => window.setTimeout(resolve, 300))
  // Changing the PIN from «Ещё» leaves fingerprint unlock as it was: it has its own switch there.
  if (route.query.from !== 'more' && !auth.biometric && (await biometricAvailable())) {
    offerBiometric.value = true
    return
  }
  await finish()
}

async function finish(): Promise<void> {
  await router.replace(route.query.from === 'more' ? { name: 'more' } : { name: 'today' })
}

async function enableBiometric(): Promise<void> {
  // One real scan first, so the switch is only on when the finger is actually recognised.
  if (await confirmBiometric({ title: t('pin.biometricTitle'), subtitle: t('pin.biometricSubtitle'), cancel: t('pin.biometricLater') })) {
    auth.setBiometric(true)
  }
  await finish()
}
</script>

<template>
  <div class="mx-auto flex min-h-dvh max-w-sm flex-col items-center px-6 pt-[max(2.5rem,calc(var(--safe-top)+1.5rem))] pb-[max(2.5rem,var(--safe-bottom))] text-center">
    <div class="flex flex-1 flex-col items-center justify-center gap-5 pb-8">
      <CompassMark :size="56" />
      <Transition name="fade" mode="out-in">
        <div v-if="offerBiometric" key="biometric" class="flex flex-col gap-1.5">
          <h1 class="text-headline font-semibold">{{ $t('pin.biometricOffer') }}</h1>
          <p class="max-w-72 text-sm text-balance text-ink-2">{{ $t('pin.biometricOfferHint') }}</p>
        </div>
        <div v-else :key="first === null ? 'create' : 'repeat'" class="flex flex-col gap-1.5">
          <h1 class="text-headline font-semibold">
            {{ first === null ? $t('pin.create') : $t('pin.repeat') }}
          </h1>
          <p class="max-w-72 text-sm text-balance" :class="mismatch ? 'text-danger' : 'text-ink-2'">
            {{ mismatch ? $t('pin.mismatch') : $t('pin.createHint') }}
          </p>
        </div>
      </Transition>
    </div>
    <Transition name="fade" mode="out-in">
      <div v-if="offerBiometric" class="flex w-full flex-col gap-2">
        <UiButton size="lg" block @click="enableBiometric">
          <FingerprintPattern class="size-5" />
          {{ $t('pin.biometricOn') }}
        </UiButton>
        <UiButton variant="ghost" block @click="finish">{{ $t('pin.biometricLater') }}</UiButton>
      </div>
      <PinPad v-else ref="pad" :error-key="errorKey" :busy="busy" :success="success" @complete="onComplete" />
    </Transition>
  </div>
</template>
