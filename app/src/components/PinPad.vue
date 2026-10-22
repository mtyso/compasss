<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Delete } from 'lucide-vue-next'
import { haptics } from '@/lib/haptics'
import { PIN_LENGTH } from '@/lib/pin'

const props = defineProps<{
  /** Bumped by the parent after a wrong PIN: shakes the dots and clears them. */
  errorKey?: number
  busy?: boolean
  /** The PIN was right: the dots turn green while the next screen opens. */
  success?: boolean
}>()

const emit = defineEmits<{ complete: [pin: string] }>()

const digits = ref('')
const shaking = ref(false)

function press(key: string): void {
  if (props.busy || props.success || digits.value.length >= PIN_LENGTH) return
  haptics.impact('light')
  digits.value += key
  if (digits.value.length === PIN_LENGTH) {
    emit('complete', digits.value)
  }
}

function erase(): void {
  if (props.busy || props.success || !digits.value) return
  haptics.selection()
  digits.value = digits.value.slice(0, -1)
}

watch(
  () => props.errorKey,
  () => {
    haptics.notify('error')
    shaking.value = true
    window.setTimeout(() => {
      shaking.value = false
      digits.value = ''
    }, 420)
  },
)

watch(
  () => props.success,
  (value) => {
    if (value) haptics.notify('success')
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
  <div class="flex flex-col items-center gap-12">
    <div class="flex gap-[18px]" :class="{ 'animate-shake': shaking }" role="status" :aria-label="`${digits.length} / ${PIN_LENGTH}`">
      <span
        v-for="i in PIN_LENGTH"
        :key="i"
        class="dot relative size-3.5 rounded-full"
        :class="[
          shaking ? 'is-error' : success ? 'is-success' : i <= digits.length ? 'is-filled' : '',
        ]"
        :style="{ transitionDelay: success ? `${(i - 1) * 40}ms` : '0ms' }"
      />
    </div>

    <div class="grid grid-cols-3 gap-x-7 gap-y-4">
      <button v-for="key in keys" :key="key" type="button" class="pin-key" @click="press(key)">
        {{ key }}
      </button>
      <span />
      <button type="button" class="pin-key" @click="press('0')">0</button>
      <button type="button" class="pin-key pin-key-plain" :aria-label="$t('pin.erase')" @click="erase">
        <Delete class="size-7" :stroke-width="1.8" />
      </button>
    </div>
  </div>
</template>

<style scoped>
@reference "../style.css";

/* Empty: a ring. Filled: the ring fills from the centre with a little pop. */
.dot {
  box-shadow: inset 0 0 0 2px color-mix(in srgb, var(--c-ink) 28%, transparent);
}

.dot::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: inherit;
  background: var(--c-ink);
  transform: scale(0);
  transition:
    transform 180ms var(--ease-exit),
    background-color var(--dur-fast) var(--ease-standard);
}

.dot.is-filled::after,
.dot.is-error::after,
.dot.is-success::after {
  transform: scale(1);
  transition:
    transform var(--dur-pop) var(--ease-pop),
    background-color var(--dur-fast) var(--ease-standard);
  transition-delay: inherit;
}

.dot.is-error::after {
  background: var(--c-danger);
}

.dot.is-success::after {
  background: var(--c-money);
}

.pin-key {
  @apply flex size-[4.75rem] items-center justify-center rounded-full bg-surface text-[1.75rem] font-medium text-ink shadow-card select-none;
  transition:
    transform var(--dur-spring) var(--ease-spring),
    background-color 300ms var(--ease-standard);
  -webkit-tap-highlight-color: transparent;
}

.pin-key:active {
  transform: scale(0.9);
  background-color: var(--c-surface-2);
  transition-duration: 60ms;
}

.pin-key-plain {
  @apply bg-transparent text-ink-2 shadow-none;
}

.pin-key-plain:active {
  background-color: color-mix(in srgb, var(--c-ink) 6%, transparent);
}

@media (prefers-reduced-motion: reduce) {
  .pin-key:active {
    transform: none;
  }
  .dot::after {
    transition-duration: 0ms !important;
  }
}
</style>
