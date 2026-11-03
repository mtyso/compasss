<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Pencil } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import TransactionRow from '@/components/money/TransactionRow.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiFact from '@/components/ui/UiFact.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { api } from '@/lib/api'
import { isoDate, shiftDate } from '@/lib/dates'
import { formatDayMonth } from '@/lib/format'
import { useMoney, useQuickAdd, type Transaction } from '@/stores/money'
import type { RecurringPayment } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

/**
 * A recurring payment, read-only: what a tap on its row opens (docs/PLAN.md, D42). The sum, when it
 * is due, where it goes out from, and the last times it was paid; «Оплачено» opens PaySheet with the
 * details, «Изменить» the payment's form. The screen that shows it opens those (`pay`, `edit`).
 */
const props = defineProps<{ payment: RecurringPayment | null }>()
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ pay: [payment: RecurringPayment]; edit: [payment: RecurringPayment] }>()

const money = useMoney()
const quick = useQuickAdd()
const settings = useSettings()
const { t } = useI18n()

const HISTORY = 6
const history = ref<Transaction[] | null>(null)

watch(open, async (value) => {
  const payment = props.payment
  if (!value || !payment) return
  history.value = null
  try {
    const page = await api<{ data: Transaction[] }>(`/transactions?recurring_payment_id=${payment.id}`)
    // A server from before the filter returns every operation: keep only this payment's.
    history.value = page.data.filter((tx) => tx.recurring_payment_id === payment.id).slice(0, HISTORY)
  } catch {
    history.value = []
  }
})

const category = computed(() => (props.payment ? money.categoryById.get(props.payment.category_id) : undefined))
const root = computed(() => (category.value ? money.rootOf(category.value) : undefined))
const account = computed(() => (props.payment?.account_id ? money.accountById.get(props.payment.account_id) : undefined))
const categoryPath = computed(() => {
  if (!category.value) return '—'
  const name = category.value.names[settings.locale]
  return root.value && root.value.id !== category.value.id ? `${root.value.names[settings.locale]} · ${name}` : name
})

/** «Срок — 21 ноября», «сегодня», or «Срок был 3 ноября, ещё не отмечен» in red. */
const due = computed(() => {
  const payment = props.payment
  if (!payment) return ''
  const today = isoDate()
  const date = formatDayMonth(payment.next_due_on, settings.locale)
  if (payment.overdue) return t('payments.dueWasOn', { date })
  if (payment.next_due_on === today) return t('details.dueToday')
  if (payment.next_due_on === shiftDate(today, 1)) return t('details.dueTomorrow')
  return t('payments.dueOn', { date })
})

function pay(): void {
  if (!props.payment) return
  open.value = false
  emit('pay', props.payment)
}

function edit(): void {
  if (!props.payment) return
  open.value = false
  emit('edit', props.payment)
}

/** A past payment opens like any operation; this sheet steps aside so the two don't stack. */
function openPaid(tx: Transaction): void {
  open.value = false
  quick.show(tx)
}
</script>

<template>
  <UiSheet v-model:open="open" :title="payment?.name ?? ''">
    <div v-if="payment" class="flex flex-col gap-4 pt-1">
      <section class="flex items-center gap-4">
        <CategoryIcon :icon="root?.icon" :color="root?.color" size="lg" />
        <div class="flex min-w-0 flex-1 flex-col gap-1">
          <MoneyAmount :amount="payment.amount" :currency="payment.currency" class="text-[2rem] leading-none font-semibold tracking-[-0.03em]" />
          <span class="text-[0.9375rem]" :class="payment.overdue ? 'font-medium text-danger' : 'text-ink-2'">{{ due }}</span>
        </div>
      </section>

      <dl class="facts">
        <UiFact :label="$t('quickAdd.category')">{{ categoryPath }}</UiFact>
        <UiFact :label="$t('payments.fromAccount')">{{ account?.name ?? $t('income.anyAccount') }}</UiFact>
        <UiFact :label="$t('details.repeats')">{{ $t('details.monthlyOn', { day: payment.day_of_month }) }}</UiFact>
        <UiFact :label="$t('payments.nextDue')">{{ formatDayMonth(payment.next_due_on, settings.locale) }}</UiFact>
        <UiFact v-if="!payment.active" :label="$t('details.status')">{{ $t('details.paused') }}</UiFact>
      </dl>

      <section v-if="history === null || history.length" class="flex flex-col gap-1.5">
        <h3 class="field-label">{{ $t('details.paidBefore') }}</h3>
        <div v-if="history === null" class="skeleton h-15 rounded-xl" />
        <div v-else class="group">
          <TransactionRow v-for="tx in history" :key="tx.id" :transaction="tx" dated @click="openPaid(tx)" />
        </div>
      </section>
    </div>

    <template v-if="payment" #footer>
      <div class="flex gap-2">
        <UiButton variant="secondary" class="flex-1" @click="edit">
          <template #icon><Pencil class="size-[18px]" :stroke-width="2.2" /></template>
          {{ $t('details.edit') }}
        </UiButton>
        <UiButton v-if="payment.active" class="flex-1" @click="pay">{{ $t('payments.pay') }}</UiButton>
      </div>
    </template>
  </UiSheet>
</template>
