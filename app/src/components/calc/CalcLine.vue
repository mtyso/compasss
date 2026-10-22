<script setup lang="ts">
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import type { Currency } from '@/lib/format'

/** One line of a calculator's result: what it is on the left, the sum (or any text) on the right. */
withDefaults(defineProps<{ label: string; amount?: number | null; currency?: Currency; tone?: 'default' | 'good' | 'warn'; sign?: 'auto' | 'always' }>(), {
  amount: null,
  currency: 'UZS',
  tone: 'default',
  sign: 'auto',
})
</script>

<template>
  <div class="flex items-baseline justify-between gap-3">
    <dt class="text-ink-2">{{ label }}</dt>
    <dd class="text-right font-semibold" :class="{ 'text-money': tone === 'good', 'text-habit': tone === 'warn' }">
      <slot><MoneyAmount v-if="amount !== null" :amount="amount" :currency="currency" :sign="sign" /></slot>
    </dd>
  </div>
</template>
