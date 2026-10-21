<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Delete } from 'lucide-vue-next'
import { PIN_LENGTH } from '@/lib/pin'

const props = defineProps<{
  /** Bumped by the parent after a wrong PIN: shakes the dots and clears them. */
  errorKey?: number
  busy?: boolean
}>()

const emit = defineEmits<{ complete: [pin: string] }>()

const digits = ref('')
const shaking = ref(false)

function press(key: string): void {
  if (props.busy || digits.value.length >= PIN_LENGTH) return
  digits.value += key
  if (digits.value.length === PIN_LENGTH) {
    emit('complete', digits.value)
  }
}

function erase(): void {
  if (!props.busy) digits.value = digits.value.slice(0, -1)
}

watch(
  () => props.errorKey,
  () => {
    shaking.value = true
    window.setTimeout(() => {
      shaking.value = false
      digits.value = ''
    }, 380)
  },
)

function reset(): void {
  digits.value = ''
}
defineExpose({ reset })

function onKey(event: KeyboardEvent): void {
  if (/^\d$/.test(event.key)) press(event.key)
  else if (event.key === 'Backspace') erase()
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9']
</script>

<template>
  <div class="flex flex-col items-center gap-10">
    <div class="flex gap-4" :class="{ 'pin-shake': shaking }" role="status" :aria-label="`${digits.length} / ${PIN_LENGTH}`">
      <span
        v-for="i in PIN_LENGTH"
        :key="i"
        class="size-3.5 rounded-full border-2 transition-colors duration-150"
        :class="
          shaking
            ? 'border-danger bg-danger'
            : i <= digits.length
              ? 'border-ink bg-ink'
              : 'border-ink-3 bg-transparent'
        "
      />
    </div>

    <div class="grid grid-cols-3 gap-x-6 gap-y-4">
      <button
        v-for="key in keys"
        :key="key"
        type="button"
        class="pin-key"
        @click="press(key)"
      >
        {{ key }}
      </button>
      <span />
      <button type="button" class="pin-key" @click="press('0')">0</button>
      <button type="button" class="pin-key !bg-transparent text-ink-2" :aria-label="$t('pin.erase')" @click="erase">
        <Delete class="size-6" />
      </button>
    </div>
  </div>
</template>

<style scoped>
@reference "../style.css";

.pin-key {
  @apply flex size-18 items-center justify-center rounded-full bg-surface text-2xl font-medium text-ink shadow-sm transition-transform duration-100 select-none active:scale-90 active:bg-line;
  -webkit-tap-highlight-color: transparent;
}

.pin-shake {
  animation: shake 360ms ease-in-out;
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  20%, 60% { transform: translateX(-8px); }
  40%, 80% { transform: translateX(8px); }
}

@media (prefers-reduced-motion: reduce) {
  .pin-shake { animation: none; }
  .pin-key { transition: none; }
}
</style>
