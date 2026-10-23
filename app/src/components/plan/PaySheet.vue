<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AccountIcon from '@/components/money/AccountIcon.vue'
import AmountField from '@/components/AmountField.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { showToast } from '@/components/ui/toast'
import { ApiError } from '@/lib/api'
import { isoDate, isoTime } from '@/lib/dates'
import { formatDayMonth } from '@/lib/format'
import { haptics } from '@/lib/haptics'
import { useMoney } from '@/stores/money'
import { usePlan, type RecurringPayment } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

/**
 * «Оплачено» with the details: another sum (a bill that changes), another account or day. Also
 * «Пропустить в этот раз» for a month with nothing to pay, and the way to the payment's settings.
 */
const props = defineProps<{ payment: RecurringPayment | null }>()
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ edit: [payment: RecurringPayment] }>()

const money = useMoney()
const plan = usePlan()
const settings = useSettings()
const { t } = useI18n()

const accountId = ref<number | null>(null)
const amount = ref<number | null>(null)
const day = ref(isoDate())
const busy = ref<'pay' | 'skip' | null>(null)
const error = ref<string | null>(null)

const sources = computed(() => money.activeAccounts.filter((a) => !a.is_cushion))
const account = computed(() => (accountId.value ? money.accountById.get(accountId.value) : undefined))
const currency = computed(() => account.value?.currency ?? props.payment?.currency ?? 'UZS')

watch(open, (value) => {
  const payment = props.payment
  if (!value || !payment) return
  error.value = null
  day.value = isoDate()
  accountId.value = payment.account_id && money.accountById.has(payment.account_id) ? payment.account_id : (sources.value[0]?.id ?? null)
  amount.value = account.value?.currency === payment.currency ? payment.amount : null
})

// Another currency: the sum has to be typed in the account's own.
watch(accountId, () => {
  if (props.payment && account.value?.currency !== props.payment.currency) amount.value = null
  else if (props.payment && amount.value === null) amount.value = props.payment.amount
})

const due = computed(() => {
  const payment = props.payment
  if (!payment) return ''
  const date = formatDayMonth(payment.next_due_on, settings.locale)
  return payment.overdue ? t('payments.dueWasOn', { date }) : t('payments.dueOn', { date })
})
const canPay = computed(() => !!accountId.value && !!amount.value && day.value <= isoDate())

async function pay(): Promise<void> {
  const payment = props.payment
  if (!payment || !canPay.value || busy.value) return
  busy.value = 'pay'
  error.value = null
  try {
    const { payment: next } = await plan.pay(payment, {
      account_id: accountId.value!,
      amount: amount.value!,
      occurred_at: `${day.value} ${day.value === isoDate() ? isoTime() : '12:00'}`,
    })
    haptics.notify('success')
    showToast({ message: t('payments.paidToast', { name: payment.name }), detail: nextDetail(next), tone: 'success' })
    open.value = false
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('quickAdd.failed')
  } finally {
    busy.value = null
  }
}

async function skip(): Promise<void> {
  const payment = props.payment
  if (!payment || busy.value) return
  busy.value = 'skip'
  error.value = null
  try {
    const next = await plan.skip(payment)
    haptics.impact('light')
    showToast({ message: t('payments.skippedToast', { name: payment.name }), detail: nextDetail(next) })
    open.value = false
  } catch {
    error.value = t('quickAdd.failed')
  } finally {
    busy.value = null
  }
}

/** «Следующий — 21 ноября», from the payment as the server returned it. */
function nextDetail(next: RecurringPayment): string {
  return t('payments.nextOn', { date: formatDayMonth(next.next_due_on, settings.locale) })
}

function edit(): void {
  if (!props.payment) return
  open.value = false
  emit('edit', props.payment)
}
</script>

<template>
  <UiSheet v-model:open="open" :title="payment?.name ?? ''">
    <div v-if="payment" class="flex flex-col gap-5 pt-1">
      <p class="-mt-2 text-sm" :class="payment.overdue ? 'font-medium text-danger' : 'text-ink-2'">{{ due }}</p>

      <section class="flex flex-col gap-2">
        <h3 class="field-label">{{ $t('payments.fromAccount') }}</h3>
        <div class="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4">
          <button
            v-for="item in sources"
            :key="item.id"
            type="button"
            class="chip"
            :class="{ 'chip-on': accountId === item.id }"
            :aria-pressed="accountId === item.id"
            @click="accountId = item.id"
          >
            <AccountIcon :type="item.type" class="size-4" />
            {{ item.name }}
          </button>
        </div>
      </section>

      <AmountField v-model="amount" :currency="currency" :label="$t('payments.paidAmount')" />

      <label class="flex flex-col gap-2">
        <span class="field-label">{{ $t('quickAdd.date') }}</span>
        <input v-model="day" type="date" :max="isoDate()" class="field w-48" />
      </label>
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <div class="flex flex-col gap-2">
        <UiButton block :disabled="!canPay" :loading="busy === 'pay'" @click="pay">
          {{ $t('payments.pay') }}
          <MoneyAmount v-if="amount" :amount="amount" :currency="currency" class="font-semibold" />
        </UiButton>
        <div class="flex gap-2">
          <UiButton variant="secondary" class="flex-1" :loading="busy === 'skip'" :disabled="busy === 'pay'" @click="skip">{{ $t('payments.skip') }}</UiButton>
          <UiButton variant="ghost" class="flex-1" :disabled="!!busy" @click="edit">{{ $t('payments.edit') }}</UiButton>
        </div>
      </div>
    </template>
  </UiSheet>
</template>
