<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AmountField from '@/components/AmountField.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { showToast } from '@/components/ui/toast'
import { ApiError } from '@/lib/api'
import { isoDate, isoTime } from '@/lib/dates'
import { formatDayMonth, formatMoney } from '@/lib/format'
import { haptics } from '@/lib/haptics'
import { useDebts, type OweRow, type OwedRow } from '@/stores/debts'
import { useGoals } from '@/stores/goals'
import { useMoney } from '@/stores/money'
import { useSettings } from '@/stores/settings'
import AccountPicker from './AccountPicker.vue'

/**
 * One debt (docs/PLAN.md, D29). Money lent: «Вернул» with how much came back and where, or «Дать ещё».
 * A debt of the user's: «Внести платёж», the way to its plan, or a payment to set when it has none.
 */
const props = defineProps<{ owe?: OweRow | null; owed?: OwedRow | null }>()
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ lendMore: [row: OwedRow] }>()

const debts = useDebts()
const goals = useGoals()
const money = useMoney()
const settings = useSettings()
const router = useRouter()
const { t } = useI18n()

const accountId = ref<number | null>(null)
const amount = ref<number | null>(null)
const day = ref(isoDate())
const payment = ref<number | null>(null)
const busy = ref<'settle' | 'plan' | null>(null)
const error = ref<string | null>(null)

const row = computed(() => props.owe ?? props.owed ?? null)
const lent = computed(() => !!props.owed)
const accounts = computed(() => money.activeAccounts.filter((a) => a.type !== 'debt' && a.type !== 'lent' && a.currency === row.value?.currency))
const fmt = (minor: number) => formatMoney(minor, row.value?.currency ?? 'UZS', settings.locale)

const subline = computed(() => {
  if (props.owed) {
    const due = props.owed.due_on ? ` · ${t(props.owed.overdue ? 'debts.overdueSince' : 'debts.dueBy', { date: formatDayMonth(props.owed.due_on, settings.locale) })}` : ''
    return t('debts.owes', { amount: fmt(props.owed.amount) }) + due
  }
  if (!props.owe) return ''
  const plan = props.owe.goal ? ` · ${t('debts.perMonth', { amount: fmt(props.owe.goal.monthly) })}` : ''
  return t('debts.left', { amount: fmt(props.owe.amount) }) + plan
})

watch(open, (value) => {
  const current = row.value
  if (!value || !current) return
  error.value = null
  day.value = isoDate()
  payment.value = null
  const preferred = props.owed?.from_account_id ?? null
  accountId.value = (preferred && accounts.value.some((a) => a.id === preferred) ? preferred : null)
    ?? (accounts.value.find((a) => a.spendable && !a.is_cushion) ?? accounts.value[0])?.id ?? null
  // Back in full; a payment of what this period asks, or a month's payment, or the whole debt.
  const goal = props.owe?.goal
  amount.value = goal ? Math.min(current.amount, goal.due > 0 ? goal.due : goal.monthly || current.amount) : current.amount
})

const canSettle = computed(() => !!accountId.value && !!amount.value && amount.value <= (row.value?.amount ?? 0) && day.value <= isoDate())

async function settle(): Promise<void> {
  const current = row.value
  if (!current || !canSettle.value || busy.value) return
  busy.value = 'settle'
  error.value = null
  try {
    await debts.settle(current.account_id, {
      account_id: accountId.value!,
      amount: amount.value!,
      occurred_at: `${day.value} ${day.value === isoDate() ? isoTime() : '12:00'}`,
    })
    haptics.notify('success')
    const message = lent.value ? t('debts.returnedToast', { name: current.name, amount: fmt(amount.value!) }) : t('debts.paidToast', { name: current.name, amount: fmt(amount.value!) })
    showToast({ message, tone: 'success' })
    open.value = false
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('quickAdd.failed')
  } finally {
    busy.value = null
  }
}

/** A debt with no plan gets a debt goal with this payment: the limit sets it aside from now on. */
async function savePlan(): Promise<void> {
  const current = props.owe
  if (!current || !payment.value || busy.value) return
  busy.value = 'plan'
  error.value = null
  try {
    await goals.save({ type: 'debt', title: current.name, account_id: current.account_id, monthly_amount: payment.value })
    await debts.load()
    haptics.notify('success')
    open.value = false
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('quickAdd.failed')
  } finally {
    busy.value = null
  }
}

function toGoal(): void {
  if (!props.owe?.goal) return
  open.value = false
  void router.push({ name: 'goal', params: { id: props.owe.goal.id } })
}

function lendMore(): void {
  if (!props.owed) return
  open.value = false
  emit('lendMore', props.owed)
}
</script>

<template>
  <UiSheet v-model:open="open" :title="row?.name ?? ''">
    <div v-if="row" class="flex flex-col gap-5 pt-1">
      <p class="-mt-2 text-sm" :class="owed?.overdue ? 'font-medium text-danger' : 'text-ink-2'">{{ subline }}</p>

      <p v-if="!accounts.length" class="card px-4 py-3 text-sm text-ink-2">{{ $t('debts.noAccount', { currency: row.currency }) }}</p>
      <template v-else>
        <AccountPicker v-model="accountId" :accounts="accounts" :label="lent ? $t('debts.toWhich') : $t('debts.fromAccount')" />
        <AmountField v-model="amount" :currency="row.currency" :label="$t('debts.howMuch')" />
        <label class="flex flex-col gap-2">
          <span class="field-label">{{ $t('debts.when') }}</span>
          <input v-model="day" type="date" :max="isoDate()" class="field w-48" />
        </label>
      </template>

      <section v-if="owe && !owe.goal" class="flex flex-col gap-3 border-t border-line pt-5">
        <div class="flex flex-col gap-1">
          <h3 class="font-medium">{{ $t('debts.planTitle') }}</h3>
          <p class="text-caption text-ink-3">{{ $t('debts.planHint') }}</p>
        </div>
        <AmountField v-model="payment" :currency="row.currency" :label="$t('debts.payment')" />
        <UiButton variant="secondary" size="sm" class="self-start" :disabled="!payment" :loading="busy === 'plan'" @click="savePlan">{{ $t('debts.savePlan') }}</UiButton>
      </section>
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <div class="flex flex-col gap-2">
        <UiButton v-if="accounts.length" block :disabled="!canSettle" :loading="busy === 'settle'" @click="settle">
          {{ lent ? $t('debts.returned') : $t('debts.pay') }}
          <MoneyAmount v-if="amount" :amount="amount" :currency="row?.currency ?? 'UZS'" class="font-semibold" />
        </UiButton>
        <UiButton v-if="owe?.goal" variant="ghost" block @click="toGoal">{{ $t('debts.openGoal') }}</UiButton>
        <UiButton v-if="owed" variant="ghost" block @click="lendMore">{{ $t('debts.lendMore') }}</UiButton>
      </div>
    </template>
  </UiSheet>
</template>
