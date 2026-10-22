<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PinPad from '@/components/PinPad.vue'
import CompassMark from '@/components/ui/CompassMark.vue'
import { useAuth } from '@/stores/auth'

const auth = useAuth()
const router = useRouter()
const route = useRoute()

const first = ref<string | null>(null)
const errorKey = ref(0)
const mismatch = ref(false)
const busy = ref(false)
const success = ref(false)
const pad = ref<InstanceType<typeof PinPad> | null>(null)

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
  await router.replace(route.query.from === 'more' ? { name: 'more' } : { name: 'today' })
}
</script>

<template>
  <div class="mx-auto flex min-h-dvh max-w-sm flex-col items-center px-6 pt-[max(2.5rem,calc(var(--safe-top)+1.5rem))] pb-[max(2.5rem,var(--safe-bottom))] text-center">
    <div class="flex flex-1 flex-col items-center justify-center gap-5 pb-8">
      <CompassMark :size="56" />
      <Transition name="fade" mode="out-in">
        <div :key="first === null ? 'create' : 'repeat'" class="flex flex-col gap-1.5">
          <h1 class="font-display text-headline font-bold">
            {{ first === null ? $t('pin.create') : $t('pin.repeat') }}
          </h1>
          <p class="max-w-72 text-sm text-balance" :class="mismatch ? 'text-danger' : 'text-ink-2'">
            {{ mismatch ? $t('pin.mismatch') : $t('pin.createHint') }}
          </p>
        </div>
      </Transition>
    </div>
    <PinPad ref="pad" :error-key="errorKey" :busy="busy" :success="success" @complete="onComplete" />
  </div>
</template>
