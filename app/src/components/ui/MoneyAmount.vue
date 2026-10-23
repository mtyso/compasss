<script setup lang="ts">
import { computed } from 'vue'
import { formatMoney, type Currency } from '@/lib/format'
import { useSettings } from '@/stores/settings'
import RollingDigits from './RollingDigits.vue'

/**
 * A sum of money set the way it reads best: digits large, the currency and cents quieter, the
 * minus a real minus sign. With `animate`, the digits roll to a new value like a counter instead
 * of jumping, and with `intro` they also roll up from zero when the sum first appears.
 *
 * `sign`: 'auto' shows a minus for negatives; 'always' adds + for positives (income in a list);
 * 'expense' always shows a minus (a spending shown as a positive number).
 */
const props = withDefaults(
  defineProps<{
    amount: number
    currency?: Currency
    sign?: 'auto' | 'always' | 'expense'
    animate?: boolean
    intro?: boolean
    /** Hide the currency (when a heading around it already says it). */
    bare?: boolean
  }>(),
  { currency: 'UZS', sign: 'auto', animate: false, intro: false, bare: false },
)

const settings = useSettings()

const parts = computed(() => {
  const value = props.amount
  const text = formatMoney(Math.abs(value), props.currency, settings.locale)
  const negative = props.sign === 'expense' ? value !== 0 : value < 0
  const prefix = negative ? '−' : props.sign === 'always' && value > 0 ? '+' : ''
  if (props.currency === 'USD') {
    const [whole, cents] = text.replace('$', '').split(',')
    return { prefix, symbol: '$', whole: whole ?? '', cents: cents ? `,${cents}` : '', suffix: '' }
  }
  const match = /^(.+?)\s(\S+)$/.exec(text)
  return { prefix, symbol: '', whole: match?.[1] ?? text, cents: '', suffix: match?.[2] ?? '' }
})

const label = computed(() => `${parts.value.prefix}${formatMoney(Math.abs(props.amount), props.currency, settings.locale)}`)
</script>

<template>
  <span class="money num inline-flex items-baseline whitespace-nowrap" :aria-label="label" role="text">
    <span aria-hidden="true" class="contents">
      <span v-if="parts.prefix" class="money-sign">{{ parts.prefix }}</span>
      <span v-if="parts.symbol && !bare" class="money-symbol">{{ parts.symbol }}</span>
      <RollingDigits v-if="animate" :text="parts.whole" :intro="intro" />
      <span v-else>{{ parts.whole }}</span>
      <span v-if="parts.cents" class="money-minor">{{ parts.cents }}</span>
      <span v-if="parts.suffix && !bare" class="money-unit">{{ parts.suffix }}</span>
    </span>
  </span>
</template>

<style scoped>
.money-sign {
  margin-right: 0.04em;
  /* A tabular minus is as wide as a digit and reads as «- 85 000»; a proportional one hugs the number. */
  font-variant-numeric: normal;
  font-feature-settings: normal;
}

/* Currency and cents: same baseline, smaller and quieter, so the eye lands on the whole part. */
.money-unit {
  margin-left: 0.28em;
  font-size: max(0.5em, 0.75rem);
  font-family: var(--font-sans);
  font-weight: 500;
  letter-spacing: 0;
  opacity: 0.62;
}

.money-symbol {
  margin-right: 0.04em;
  font-size: 0.72em;
  opacity: 0.7;
}

.money-minor {
  font-size: 0.62em;
  opacity: 0.62;
}
</style>
