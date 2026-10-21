<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PinPad from '@/components/PinPad.vue'
import { useAuth } from '@/stores/auth'

const auth = useAuth()
const router = useRouter()
const route = useRoute()

const first = ref<string | null>(null)
const errorKey = ref(0)
const mismatch = ref(false)
const busy = ref(false)
const pad = ref<InstanceType<typeof PinPad> | null>(null)

async function onComplete(pin: string): Promise<void> {
  if (first.value === null) {
    first.value = pin
    mismatch.value = false
    pad.value?.reset()
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
  await router.replace(route.query.from === 'more' ? { name: 'more' } : { name: 'today' })
}
</script>

<template>
  <div class="mx-auto flex min-h-full max-w-sm flex-col items-center justify-center gap-10 px-6 py-10 text-center">
    <div class="flex flex-col gap-2">
      <h1 class="font-display text-2xl font-bold">
        {{ first === null ? $t('pin.create') : $t('pin.repeat') }}
      </h1>
      <p class="text-sm" :class="mismatch ? 'text-danger' : 'text-ink-2'">
        {{ mismatch ? $t('pin.mismatch') : $t('pin.createHint') }}
      </p>
    </div>
    <PinPad ref="pad" :error-key="errorKey" :busy="busy" @complete="onComplete" />
  </div>
</template>
