<script setup lang="ts">
import { computed } from 'vue'
import { formatDayMonth, formatMoney } from '@/lib/format'
import type { DailyLimit } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

const props = defineProps<{ limit: DailyLimit; incomeName?: string | null }>()
const settings = useSettings()

const over = computed(() => props.limit.left_today_uzs < 0)
const share = computed(() =>
  props.limit.limit_uzs > 0 ? Math.min(1, props.limit.spent_today_uzs / props.limit.limit_uzs) : props.limit.spent_today_uzs > 0 ? 1 : 0,
)
const money = (value: number) => formatMoney(value, 'UZS', settings.locale)
</script>

<template>
  <article class="flex flex-col gap-3 rounded-3xl bg-surface p-5 shadow-sm">
    <span class="text-[11px] font-semibold tracking-wider text-ink-3 uppercase">
      {{ over ? $t('today.over') : $t('today.limit') }}
    </span>
    <span class="num font-display text-4xl font-bold" :class="over ? 'text-danger' : 'text-money'">
      {{ money(Math.abs(limit.left_today_uzs)) }}
    </span>
    <div class="h-2 overflow-hidden rounded-full bg-money-soft">
      <div class="limit-bar h-full rounded-full" :class="over ? 'bg-danger' : 'bg-money'" :style="{ width: `${share * 100}%` }" />
    </div>
    <p class="text-sm text-ink-2">
      {{ $t('today.perDay', { amount: money(limit.limit_uzs) }) }}
      ·
      <template v-if="limit.estimated_end">{{ $t('today.untilMonthEnd', limit.days_left) }}</template>
      <template v-else>{{ $t('today.untilIncome', { name: incomeName ?? $t('today.income'), date: formatDayMonth(limit.until, settings.locale) }) }}, {{ $t('today.daysLeft', limit.days_left) }}</template>
    </p>
    <dl class="grid grid-cols-2 gap-3 border-t border-line pt-3 text-sm">
      <div class="flex flex-col">
        <dt class="text-xs text-ink-3">{{ $t('today.free') }}</dt>
        <dd class="num font-semibold" :class="{ 'text-danger': limit.free_uzs < 0 }">{{ money(limit.free_uzs) }}</dd>
      </div>
      <div class="flex flex-col">
        <dt class="text-xs text-ink-3">{{ $t('today.setAside') }}</dt>
        <dd class="num font-semibold">{{ money(limit.upcoming_payments_uzs + limit.min_balance_uzs) }}</dd>
      </div>
    </dl>
    <RouterLink v-if="limit.estimated_end" :to="{ name: 'income' }" class="text-sm font-medium text-money">{{ $t('today.addIncome') }}</RouterLink>
  </article>
</template>

<style scoped>
.limit-bar {
  transition: width 500ms cubic-bezier(0.2, 0.8, 0.2, 1);
}
</style>
