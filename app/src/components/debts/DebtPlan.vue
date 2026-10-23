<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AmountField from '@/components/AmountField.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import { showToast } from '@/components/ui/toast'
import { isoMonth, shiftMonth } from '@/lib/dates'
import { orderDebts, payoff, type DebtInput, type DebtMethod } from '@/lib/debts'
import { formatMoney, formatMonthTitle, formatMonthUntil } from '@/lib/format'
import { haptics } from '@/lib/haptics'
import { useDebts, type OweRow } from '@/stores/debts'
import { useSettings } from '@/stores/settings'

/**
 * «Что гасить первым» (docs/PLAN.md, D29): the avalanche or the snowball, money beyond the payments, and
 * what that does to the last payment and the interest against things as they are. «Направить» puts the
 * extra on the first debt in the order; the order is kept for the next debt paid off.
 */
const props = defineProps<{ debts: OweRow[]; method: DebtMethod }>()

const store = useDebts()
const settings = useSettings()
const { t } = useI18n()

const method = ref<DebtMethod>(props.method)
const extra = ref<number | null>(null)
const saving = ref(false)
watch(
  () => props.method,
  (value) => (method.value = value),
)

const methodOptions = computed(() => (['avalanche', 'snowball'] as const).map((value) => ({ value, label: t(`debts.methods.${value}`) })))
const inputs = computed<DebtInput[]>(() =>
  props.debts.map((d) => ({ id: d.account_id, amount: d.amount_uzs, rate: d.rate, payment: d.goal?.monthly_uzs ?? 0, first: d.goal?.due_uzs ?? 0 })),
)
const now = computed(() => payoff(inputs.value, 0, null))
const chosen = computed(() => payoff(inputs.value, extra.value ?? 0, method.value))
const first = computed(() => props.debts.find((d) => d.account_id === orderDebts(inputs.value, method.value)[0]?.id))
const nameOf = (id: number) => props.debts.find((d) => d.account_id === id)?.name ?? ''
const uzs = (value: number) => formatMoney(value, 'UZS', settings.locale)

/** The month a debt goes in, counted from this one: as a title, or after «до». */
const monthOf = (month: number) => formatMonthTitle(shiftMonth(isoMonth(), month - 1), settings.locale)
const untilMonth = (month: number) => formatMonthUntil(shiftMonth(isoMonth(), month - 1), settings.locale)

const saves = computed(() => {
  if (now.value.months === null || chosen.value.months === null) return null
  const money = now.value.interest - chosen.value.interest
  const months = now.value.months - chosen.value.months
  return money > 0 || months > 0 ? t('debts.saves', { amount: uzs(Math.max(0, money)), n: Math.max(0, months) }) : null
})

const changed = computed(() => method.value !== props.method || (extra.value ?? 0) > 0)

async function apply(): Promise<void> {
  if (saving.value) return
  saving.value = true
  try {
    const added = extra.value ?? 0
    await store.plan(method.value, added)
    haptics.notify('success')
    showToast({
      message: added > 0 && first.value ? t('debts.appliedToast', { name: first.value.name, amount: uzs(added) }) : t('debts.orderSaved'),
      tone: 'success',
    })
    extra.value = null
  } catch {
    haptics.notify('error')
    showToast({ message: t('quickAdd.failed'), tone: 'danger' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-4">
    <h2 class="section-title">{{ $t('debts.order') }}</h2>
    <div class="flex flex-col gap-2">
      <UiSegmented v-model="method" :options="methodOptions" :label="$t('debts.order')" />
      <p class="text-caption text-ink-3">{{ $t(`debts.methodHints.${method}`) }}</p>
    </div>
    <AmountField v-model="extra" currency="UZS" :label="$t('debts.extra')" />

    <div class="card flex flex-col gap-4 px-4 py-4">
      <div v-if="chosen.months !== null" class="flex flex-col gap-1">
        <span class="text-caption text-ink-3">{{ $t('debts.lastPayment') }}</span>
        <span class="text-[1.5rem] leading-tight font-semibold tracking-[-0.02em]">{{ monthOf(chosen.months) }}</span>
        <span class="num text-caption text-ink-2">
          {{ $t('debts.inMonths', { n: chosen.months }) }}<template v-if="chosen.interest > 0"> · {{ $t('debts.interest', { amount: uzs(chosen.interest) }) }}</template>
        </span>
      </div>
      <p v-else class="text-sm font-medium text-danger">{{ $t('debts.never') }}</p>

      <ol class="flex flex-col gap-2">
        <li v-for="(step, index) in chosen.order" :key="step.id" class="flex items-baseline justify-between gap-3 text-[0.9375rem]">
          <span class="min-w-0 truncate">{{ $t('debts.step', { n: index + 1, name: nameOf(step.id) }) }}</span>
          <span class="shrink-0 text-caption" :class="step.month === null ? 'text-danger' : 'text-ink-3'">
            {{ step.month === null ? $t('debts.stays') : $t('debts.goesIn', { month: untilMonth(step.month) }) }}
          </span>
        </li>
      </ol>

      <p class="border-t border-line pt-3 text-caption text-ink-3">
        <template v-if="now.months === null">{{ $t('debts.nowNever') }}</template>
        <template v-else>{{ $t('debts.now', { when: untilMonth(now.months), amount: uzs(now.interest) }) }}</template>
        <span v-if="saves" class="font-medium text-money"> · {{ saves }}</span>
      </p>
    </div>

    <div v-if="changed" class="flex flex-col gap-2">
      <p v-if="(extra ?? 0) > 0 && first" class="text-caption text-ink-3">{{ $t('debts.applyHint', { name: first.name }) }}</p>
      <UiButton block :loading="saving" @click="apply">
        {{ (extra ?? 0) > 0 && first ? $t('debts.apply', { amount: uzs(extra ?? 0) }) : $t('debts.keepOrder') }}
      </UiButton>
    </div>
  </section>
</template>
