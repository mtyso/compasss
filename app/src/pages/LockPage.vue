<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PinPad from '@/components/PinPad.vue'
import { MAX_PIN_ATTEMPTS, useAuth } from '@/stores/auth'

const auth = useAuth()
const router = useRouter()
const route = useRoute()

const errorKey = ref(0)
const busy = ref(false)

async function onComplete(pin: string): Promise<void> {
  busy.value = true
  const ok = await auth.unlock(pin)
  busy.value = false
  if (ok) {
    const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/'
    await router.replace(next)
  } else {
    errorKey.value++
  }
}
</script>

<template>
  <div class="mx-auto flex min-h-full max-w-sm flex-col items-center justify-center gap-10 px-6 py-10 text-center">
    <div class="flex flex-col gap-2">
      <h1 class="font-display text-2xl font-bold">{{ $t('pin.enter') }}</h1>
      <p class="text-sm" :class="auth.pinAttemptsLeft < MAX_PIN_ATTEMPTS ? 'text-danger' : 'text-ink-2'">
        <template v-if="auth.pinAttemptsLeft < MAX_PIN_ATTEMPTS">
          {{ $t('pin.wrong', { n: auth.pinAttemptsLeft }) }}
        </template>
        <template v-else>{{ auth.user?.name ? $t('pin.hello', { name: auth.user.name }) : ' ' }}</template>
      </p>
    </div>
    <PinPad :error-key="errorKey" :busy="busy" @complete="onComplete" />
    <button type="button" class="text-sm text-ink-2" @click="auth.logout()">{{ $t('pin.forgot') }}</button>
  </div>
</template>
