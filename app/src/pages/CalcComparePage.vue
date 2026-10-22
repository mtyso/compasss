<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AmountField from '@/components/AmountField.vue'
import CalcLine from '@/components/calc/CalcLine.vue'
import CalcNumber from '@/components/calc/CalcNumber.vue'
import { persisted, useCalcText, useGoalFromRoute } from '@/components/calc/useCalc'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import { dateAtNoon, isoDate } from '@/lib/dates'
import { comparePurchase, perDay, summarizePurchase, type BuyKind, type BuyOption, type BuyPlan } from '@/lib/finance'
import { formatDate, formatMoney, type Currency } from '@/lib/format'
import { daysBetween } from '@/lib/goals'
import { haptics } from '@/lib/haptics'
import { useSettings } from '@/stores/settings'

const { t } = useI18n()
const settings = useSettings()
const text = useCalcText()

interface LoanTerms {
  downPercent: number | null
  months: number | null
  rate: number | null
  /** A price of its own for this way of paying; null: the same as for the others. */
  price: number | null
  ownPrice: boolean
}

const defaults = {
  price: null as number | null,
  saved: null as number | null,
  monthly: null as number | null,
  depositRate: null as number | null,
  currency: 'UZS' as Currency,
  installment: { downPercent: 30, months: 12, rate: 0, price: null, ownPrice: false } as LoanTerms,
  credit: { downPercent: 30, months: 36, rate: null, price: null, ownPrice: false } as LoanTerms,
}

// Opened from a goal: its price and installment terms, what it has and what it gets a month.
const { fromGoal, goal } = useGoalFromRoute((g) => {
  const down = g.installment?.down_percent ?? g.down_percent ?? 30
  Object.assign(input, {
    price: g.installment?.price ?? g.price ?? g.target_amount ?? null,
    saved: g.saved || null,
    monthly: g.monthly || null,
    currency: g.currency ?? 'UZS',
    installment: { ...input.installment, downPercent: down, months: g.installment?.months ?? g.loan_months ?? 12, rate: g.installment?.rate ?? g.rate ?? 0 },
    credit: { ...input.credit, downPercent: down },
  })
})
const input = persisted('kompas.calc.compare', defaults, !fromGoal)

const KINDS: BuyKind[] = ['cash', 'installment', 'credit']

function terms(kind: BuyKind): LoanTerms | null {
  return kind === 'cash' ? null : input[kind]
}

/** The option a card stands for, or null while it misses a number (a bank's rate, a term). */
function optionOf(kind: BuyKind): BuyOption | null {
  if (!input.price) return null
  const loan = terms(kind)
  if (!loan) return { kind, price: input.price, downPercent: 100, months: 0, ratePercent: 0 }
  if (loan.rate === null || !loan.months || loan.downPercent === null) return null
  return {
    kind,
    price: loan.ownPrice && loan.price ? loan.price : input.price,
    downPercent: Math.min(100, loan.downPercent),
    months: loan.months,
    ratePercent: loan.rate,
  }
}

const today = isoDate()
const comparison = computed(() => {
  const options = KINDS.map((kind) => optionOf(kind))
  const ready = options.flatMap((option, i) => (option ? [{ option, i }] : []))
  if (!ready.length) return null
  const result = comparePurchase(
    ready.map(({ option }) => option),
    { basePrice: input.price!, saved: input.saved ?? 0, monthly: input.monthly ?? 0, depositRate: input.depositRate ?? 0, currency: input.currency, today },
  )
  // Back from the compared options to the three cards.
  const plans: (BuyPlan | null)[] = KINDS.map(() => null)
  ready.forEach(({ i }, k) => (plans[i] = result.plans[k]!))
  const card = (k: number) => ready[k]!.i
  return {
    plans,
    soonest: result.soonest.map(card),
    cheapest: result.cheapest.map(card),
    summary: summarizePurchase(result),
    card,
  }
})

const summary = computed(() => {
  const c = comparison.value
  const s = c?.summary
  if (!c || !s) return null
  const name = (k: number) => t(`calc.kindsBuy.${KINDS[c.card(k)]}`)
  if (s.kind === 'both') return t('calc.summaryBoth', { name: name(s.index) })
  return t('calc.summaryTradeoff', { fast: name(s.fast), cheap: name(s.cheap), span: text.span(s.daysSooner), amount: text.short(s.costsMore, input.currency) })
})

/** One card a way of paying: its terms to edit, its plan once it can be counted, and its marks. */
const cards = computed(() =>
  KINDS.map((kind, i) => ({
    kind,
    terms: terms(kind),
    plan: comparison.value?.plans[i] ?? null,
    soonest: comparison.value?.soonest.includes(i) ?? false,
    cheapest: comparison.value?.cheapest.includes(i) ?? false,
  })),
)

const fmt = (amount: number) => formatMoney(amount, input.currency, settings.locale)

function toggleOwnPrice(loan: LoanTerms): void {
  haptics.selection()
  loan.ownPrice = !loan.ownPrice
  if (loan.ownPrice && !loan.price) loan.price = input.price
}
</script>

<template>
  <div class="page page-wide">
    <ScreenHeader :title="$t('calc.compare.title')" :overline="goal ? $t('calc.fromGoal', { goal: goal.title }) : undefined" back="/more/calc" />

    <div class="page-columns">
      <div class="page-col">
        <form class="flex flex-col gap-5" @submit.prevent>
          <AmountField v-model="input.price" :currency="input.currency" :label="$t('calc.price')" />
          <AmountField v-model="input.saved" :currency="input.currency" :label="$t('calc.saved')" allow-zero />
          <AmountField v-model="input.monthly" :currency="input.currency" :label="$t('calc.putMonthly')" allow-zero />
          <CalcNumber v-model="input.depositRate" :label="$t('calc.depositRate')" :suffix="$t('calc.perYear')" :hint="$t('calc.depositRateHint')" />
        </form>
      </div>

      <div class="page-col">
        <section class="flex flex-col gap-3">
          <h2 class="section-title">{{ $t('calc.options') }}</h2>
          <p v-if="!input.price || !input.monthly" class="rounded-card bg-surface-2 p-4 text-sm text-ink-2">{{ $t('calc.enterCompare') }}</p>
          <p v-else-if="summary" class="rounded-control bg-money-soft px-4 py-3 text-sm font-medium" aria-live="polite">{{ summary }}</p>

          <article v-for="card in cards" :key="card.kind" class="card flex flex-col gap-4 p-4" data-panel>
            <header class="flex flex-wrap items-center justify-between gap-2">
              <h3 class="text-section font-semibold">{{ $t(`calc.kindsBuy.${card.kind}`) }}</h3>
              <p v-if="card.soonest || card.cheapest" class="flex gap-3 text-caption font-medium text-money">
                <span v-if="card.soonest">{{ $t('calc.soonest') }}</span>
                <span v-if="card.cheapest">{{ $t('calc.cheapest') }}</span>
              </p>
            </header>

            <template v-if="card.terms">
              <div class="grid grid-cols-3 gap-2">
                <CalcNumber v-model="card.terms.downPercent" :label="$t('calc.downShort')" suffix="%" size="sm" />
                <CalcNumber v-model="card.terms.months" :label="$t('calc.termShort')" :suffix="$t('calc.monthsUnit')" size="sm" />
                <CalcNumber v-model="card.terms.rate" :label="$t('calc.rateShort')" suffix="%" size="sm" :placeholder="card.kind === 'credit' ? '—' : '0'" />
              </div>
              <AmountField v-if="card.terms.ownPrice" v-model="card.terms.price" :currency="input.currency" :label="$t('calc.priceFor')" />
              <button type="button" class="section-link self-start" @click="toggleOwnPrice(card.terms)">
                {{ card.terms.ownPrice ? $t('calc.samePrice') : $t('calc.otherPrice') }}
              </button>
            </template>

            <div v-if="card.plan" class="flex flex-col gap-3">
              <div v-if="card.plan.buyOn" class="flex flex-col gap-0.5">
                <span class="text-caption text-ink-2">{{ $t('calc.buyOn') }}</span>
                <p class="flex flex-wrap items-baseline gap-x-2">
                  <span class="text-headline font-semibold">{{ text.month(card.plan.buyOn) }}</span>
                  <span class="text-caption text-ink-3">{{ card.plan.saveMonths ? $t('calc.inSpan', { span: text.span(daysBetween(today, card.plan.buyOn)) }) : $t('calc.now') }}</span>
                </p>
              </div>
              <p v-else class="text-sm text-ink-2">{{ $t('calc.never') }}</p>

              <dl class="flex flex-col gap-2 text-[0.9375rem]">
                <CalcLine v-if="card.plan.loan > 0" :label="$t('calc.down')" :amount="card.plan.down" :currency="input.currency" />
                <CalcLine
                  v-if="card.plan.loan > 0"
                  :label="card.plan.freeOn ? $t('calc.paymentUntil', { date: formatDate(dateAtNoon(card.plan.freeOn)) }) : $t('calc.payment')"
                  :amount="card.plan.payment"
                  :currency="input.currency"
                />
                <CalcLine :label="$t('calc.total')" :amount="card.plan.totalPaid" :currency="input.currency" />
                <CalcLine v-if="card.plan.overpay !== 0" :label="$t('calc.overpay')" :amount="card.plan.overpay" :currency="input.currency" :tone="card.plan.overpay > 0 ? 'warn' : 'good'" />
                <CalcLine v-if="card.plan.earned > 0" :label="$t('calc.depositAdds')" :amount="card.plan.earned" :currency="input.currency" tone="good" sign="always" />
              </dl>
              <p v-if="card.plan.payment > 0 && input.monthly" class="text-caption text-ink-2">
                {{ card.plan.payment > input.monthly ? $t('calc.heavier', { amount: fmt(perDay(card.plan.payment - input.monthly)) }) : $t('calc.lighter') }}
              </p>
            </div>
            <p v-else-if="input.price && card.terms && card.terms.rate === null" class="text-sm text-ink-2">{{ $t('calc.rateNeeded') }}</p>
          </article>
        </section>
      </div>
    </div>
  </div>
</template>
