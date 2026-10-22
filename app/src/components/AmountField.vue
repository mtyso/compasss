<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Currency } from '@/lib/format'
import { amountToInput, parseAmount } from '@/lib/money'

/** A money input in the units people type («32к», «1,5м»), bound to minor units. */
const props = defineProps<{ currency: Currency; label: string; allowZero?: boolean }>()
const model = defineModel<number | null>({ required: true })

const text = ref(model.value ? amountToInput(model.value, props.currency) : '')

watch(model, (value) => {
  if (value !== parsed.value) text.value = value ? amountToInput(value, props.currency) : ''
})

const parsed = computed(() => (props.allowZero && text.value.trim() === '0' ? 0 : parseAmount(text.value, props.currency)))
watch(parsed, (value) => {
  if (value !== null || text.value.trim() === '') model.value = value
})
</script>

<template>
  <label class="flex flex-col gap-1.5">
    <span class="px-1 text-xs font-semibold text-ink-3">{{ label }}</span>
    <span class="field flex items-baseline gap-2">
      <input v-model="text" inputmode="decimal" placeholder="0" class="num min-w-0 flex-1 bg-transparent outline-none" />
      <span class="text-sm text-ink-3">{{ currency === 'USD' ? '$' : $t('quickAdd.som') }}</span>
    </span>
  </label>
</template>
