<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AmountField from '@/components/AmountField.vue'
import CalcLine from '@/components/calc/CalcLine.vue'
import CalcNumber from '@/components/calc/CalcNumber.vue'
import SplitBar from '@/components/calc/SplitBar.vue'
import { persisted, useCalcText } from '@/components/calc/useCalc'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import { loanSchedule, perDay, type PaymentKind } from '@/lib/finance'
import { formatMoney, type Currency } from '@/lib/format'
import { useSettings } from '@/stores/settings'

const { t } = useI18n()
const settings = useSettings()
const text = useCalcText()

const input = persisted('kompas.calc.loan', {
  principal: null as number | null,
  currency: 'UZS' as Currency,
  months: 12 as number | null,
  rate: null as number | null,
  kind: 'annuity' as PaymentKind,
  extra: null as number | null,
})

const MONTHS = [6, 12, 24, 36, 48, 60]
const SHOWN_ROWS = 12
const showAll = ref(false)

const currencies = computed(() => [
  { value: 'UZS' as Currency, label: t('accounts.som') },
  { value: 'USD' as Currency, label: t('accounts.dollar') },
])
const kinds = computed(() => (['annuity', 'differentiated'] as PaymentKind[]).map((value) => ({ value, label: t(`calc.kinds.${value}`) })))

const ready = computed(() => !!input.principal && !!input.months)
const base = computed(() =>
  ready.value ? loanSchedule({ principal: input.principal!, months: input.months!, ratePercent: input.rate ?? 0, kind: input.kind }) : null,
)
const loan = computed(() =>
  ready.value && input.extra
    ? loanSchedule({ principal: input.principal!, months: input.months!, ratePercent: input.rate ?? 0, kind: input.kind, extraMonthly: input.extra })
    : base.value,
)
const saved = computed(() => (base.value && loan.value && loan.value !== base.value ? { months: base.value.months - loan.value.months, interest: base.value.interest - loan.value.interest } : null))
const rows = computed(() => (showAll.value ? loan.value?.rows : loan.value?.rows.slice(0, SHOWN_ROWS)) ?? [])
const fmt = (amount: number) => formatMoney(amount, input.currency, settings.locale)
</script>

<template>
  <div class="page page-wide">
    <ScreenHeader :title="$t('calc.loan.title')" back="/more/calc" />

    <div class="page-columns">
      <div class="page-col">
        <form class="flex flex-col gap-5" @submit.prevent>
          <AmountField v-model="input.principal" :currency="input.currency" :label="$t('calc.principal')" />
          <UiSegmented v-model="input.currency" :options="currencies" :label="$t('calc.currency')" size="sm" />
          <CalcNumber v-model="input.months" :label="$t('calc.term')" :suffix="$t('calc.monthsUnit')" :presets="MONTHS" :preset-label="(m) => $t('calc.months', { n: m })" />
          <CalcNumber v-model="input.rate" :label="$t('calc.rate')" :suffix="$t('calc.perYear')" :hint="$t('calc.rateHint')" />
          <section class="flex flex-col gap-2">
            <h2 class="field-label">{{ $t('calc.kind') }}</h2>
            <UiSegmented v-model="input.kind" :options="kinds" :label="$t('calc.kind')" />
            <p class="text-caption text-ink-3">{{ $t(`calc.kindHints.${input.kind}`) }}</p>
          </section>
          <section class="flex flex-col gap-2">
            <AmountField v-model="input.extra" :currency="input.currency" :label="$t('calc.extra')" allow-zero />
            <p class="text-caption text-ink-3">{{ $t('calc.extraHint') }}</p>
          </section>
        </form>
      </div>

      <div class="page-col">
        <p v-if="!loan" class="rounded-card bg-surface-2 p-4 text-sm text-ink-2">{{ $t('calc.enterLoan') }}</p>
        <template v-else>
          <section v-if="base" class="card flex flex-col gap-1.5 p-5" aria-live="polite" data-panel>
            <span class="text-caption text-ink-2">{{ $t('calc.payment') }}</span>
            <MoneyAmount v-if="input.kind === 'annuity' || base.months === 1" :amount="base.firstPayment" :currency="input.currency" animate class="text-title font-semibold" />
            <span v-else class="text-headline font-semibold">{{ $t('calc.paymentRange', { first: fmt(base.firstPayment), last: fmt(base.lastPayment) }) }}</span>
            <span v-if="saved" class="text-sm font-medium">{{ $t('calc.withExtra', { amount: fmt(loan.firstPayment) }) }}</span>
            <span class="text-caption text-ink-3">{{ $t('calc.perDay', { amount: fmt(perDay(loan.firstPayment)) }) }}</span>
          </section>

          <section class="flex flex-col gap-3">
            <dl class="flex flex-col gap-2 text-[0.9375rem]">
              <CalcLine :label="$t('calc.principal')" :amount="input.principal" :currency="input.currency" />
              <CalcLine :label="$t('calc.interest')" :amount="loan.interest" :currency="input.currency" :tone="loan.interest > 0 ? 'warn' : 'default'" />
              <CalcLine :label="$t('calc.totalBack')" :amount="loan.totalPaid" :currency="input.currency" />
              <CalcLine :label="$t('calc.term')">
                {{ saved ? $t('calc.termShorter', { n: $t('calc.months', { n: loan.months }), m: $t('calc.months', { n: base!.months }) }) : $t('calc.months', { n: loan.months }) }}
              </CalcLine>
            </dl>
            <SplitBar :first="input.principal ?? 0" :second="loan.interest" :first-label="$t('calc.principalPart')" :second-label="$t('calc.interestPart')" second-tone="habit" />
            <p v-if="saved && saved.months > 0" class="rounded-control bg-money-soft px-4 py-3 text-sm">
              {{ $t('calc.extraSaves', { span: text.spanMonths(saved.months), amount: fmt(saved.interest) }) }}
            </p>
          </section>

          <section class="flex flex-col gap-3">
            <h2 class="section-title">{{ $t('calc.schedule') }}</h2>
            <table class="num w-full text-sm">
              <thead class="text-caption text-ink-3">
                <tr>
                  <th class="py-1.5 pr-2 text-left font-medium">{{ $t('calc.scheduleMonth') }}</th>
                  <th class="py-1.5 pr-2 text-right font-medium">{{ $t('calc.schedulePayment') }}</th>
                  <th class="py-1.5 pr-2 text-right font-medium">{{ $t('calc.scheduleInterest') }}</th>
                  <th class="py-1.5 text-right font-medium">{{ $t('calc.scheduleBalance') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.month" class="border-t border-line">
                  <td class="py-2 pr-2 text-ink-3">{{ row.month }}</td>
                  <td class="py-2 pr-2 text-right font-medium"><MoneyAmount :amount="row.payment" :currency="input.currency" bare /></td>
                  <td class="py-2 pr-2 text-right text-ink-2"><MoneyAmount :amount="row.interest" :currency="input.currency" bare /></td>
                  <td class="py-2 text-right text-ink-2"><MoneyAmount :amount="row.balance" :currency="input.currency" bare /></td>
                </tr>
              </tbody>
            </table>
            <UiButton v-if="!showAll && loan.rows.length > SHOWN_ROWS" variant="secondary" size="sm" class="self-start" @click="showAll = true">
              {{ $t('calc.showAll', { n: loan.rows.length }) }}
            </UiButton>
          </section>
        </template>
      </div>
    </div>
  </div>
</template>
