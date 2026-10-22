<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AmountField from '@/components/AmountField.vue'
import CalcLine from '@/components/calc/CalcLine.vue'
import CalcNumber from '@/components/calc/CalcNumber.vue'
import SplitBar from '@/components/calc/SplitBar.vue'
import { persisted, useCalcText, useGoalFromRoute } from '@/components/calc/useCalc'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import UiSwitch from '@/components/ui/UiSwitch.vue'
import { isoDate } from '@/lib/dates'
import { depositGrowth } from '@/lib/finance'
import type { Currency } from '@/lib/format'
import { MONTH_DAYS, daysBetween } from '@/lib/goals'

const { t } = useI18n()
const text = useCalcText()

const defaults = {
  initial: null as number | null,
  monthly: null as number | null,
  currency: 'UZS' as Currency,
  rate: null as number | null,
  months: 12 as number | null,
  capitalize: true,
}
// Opened from a goal: its jar, its contribution and the months to its deadline.
const { fromGoal, goal } = useGoalFromRoute((g) => {
  Object.assign(input, {
    initial: g.saved || null,
    monthly: g.monthly || null,
    currency: g.currency ?? 'UZS',
    months: g.deadline ? Math.max(1, Math.round(daysBetween(isoDate(), g.deadline) / MONTH_DAYS)) : 12,
  })
})
const input = persisted('kompas.calc.deposit', defaults, !fromGoal)

const MONTHS = [3, 6, 12, 18, 24, 36]

const currencies = computed(() => [
  { value: 'UZS' as Currency, label: t('accounts.som') },
  { value: 'USD' as Currency, label: t('accounts.dollar') },
])

const result = computed(() =>
  (input.initial || input.monthly) && input.rate && input.months
    ? depositGrowth({ initial: input.initial ?? 0, monthly: input.monthly ?? 0, ratePercent: input.rate, months: input.months, capitalize: input.capitalize })
    : null,
)
// What the interest is worth to the goal: as many days of its contributions.
const goalDays = computed(() => (goal.value && goal.value.monthly > 0 && result.value ? (result.value.interest / goal.value.monthly) * MONTH_DAYS : null))
</script>

<template>
  <div class="page page-wide">
    <ScreenHeader :title="$t('calc.deposit.title')" :overline="goal ? $t('calc.fromGoal', { goal: goal.title }) : undefined" back="/more/calc" />

    <div class="page-columns">
      <div class="page-col">
        <form class="flex flex-col gap-5" @submit.prevent>
          <AmountField v-model="input.initial" :currency="input.currency" :label="$t('calc.initial')" allow-zero />
          <AmountField v-model="input.monthly" :currency="input.currency" :label="$t('calc.monthly')" allow-zero />
          <UiSegmented v-model="input.currency" :options="currencies" :label="$t('calc.currency')" size="sm" />
          <CalcNumber v-model="input.rate" :label="$t('calc.rate')" :suffix="$t('calc.perYear')" />
          <CalcNumber v-model="input.months" :label="$t('calc.term')" :suffix="$t('calc.monthsUnit')" :presets="MONTHS" :preset-label="(m) => $t('calc.months', { n: m })" />
          <div class="group">
            <UiSwitch v-model="input.capitalize" :title="$t('calc.capitalize')" :hint="$t('calc.capitalizeHint')" />
          </div>
        </form>
      </div>

      <div class="page-col">
        <p v-if="!result" class="rounded-card bg-surface-2 p-4 text-sm text-ink-2">{{ $t('calc.enterDeposit') }}</p>
        <template v-else>
          <section class="card flex flex-col gap-1.5 p-5" aria-live="polite" data-panel>
            <span class="text-caption text-ink-2">{{ $t('calc.willBe', { span: $t('calc.months', { n: input.months }) }) }}</span>
            <MoneyAmount :amount="result.total" :currency="input.currency" animate class="text-title font-semibold" />
            <span class="text-caption text-money">+<MoneyAmount :amount="result.interest" :currency="input.currency" /></span>
          </section>

          <section class="flex flex-col gap-3">
            <dl class="flex flex-col gap-2 text-[0.9375rem]">
              <CalcLine :label="$t('calc.own')" :amount="result.contributed" :currency="input.currency" />
              <CalcLine :label="$t('calc.earned')" :amount="result.interest" :currency="input.currency" tone="good" sign="always" />
            </dl>
            <SplitBar :first="result.contributed" :second="result.interest" :first-label="$t('calc.own')" :second-label="$t('calc.earned')" second-tone="money" />
            <p v-if="goal && goalDays !== null && goalDays >= 1" class="rounded-control bg-money-soft px-4 py-3 text-sm">
              {{ $t('calc.forGoal', { goal: goal.title, span: text.span(goalDays) }) }}
            </p>
            <p class="text-caption text-ink-3">{{ $t('calc.depositNote') }}</p>
          </section>
        </template>
      </div>
    </div>
  </div>
</template>
