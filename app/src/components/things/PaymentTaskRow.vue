<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import TaskCheck from '@/components/things/TaskCheck.vue'
import { usePay } from '@/components/plan/usePay'
import { dueLabel } from '@/lib/tasks'
import type { RecurringPayment } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

/**
 * A recurring payment among the tasks of «Дела»: ticking it off is «Оплачено» (D28). Its ring is red when
 * it is overdue and orange on the day; the sum stands on the right, as in the money screens.
 */
const props = defineProps<{ payment: RecurringPayment; today: string; hideToday?: boolean }>()
/** `open` is a tap on the row, or a tick that needs the form (another currency, no account). */
const emit = defineEmits<{ open: [] }>()

const { t } = useI18n()
const settings = useSettings()
const { account, paying, pay } = usePay(() => props.payment)

const due = computed(() =>
  props.hideToday && props.payment.next_due_on === props.today ? null : dueLabel({ due_on: props.payment.next_due_on, due_time: null }, props.today, t, settings.locale),
)
const urgency = computed(() => (props.payment.overdue ? 2 : props.payment.next_due_on === props.today ? 1 : 0))

async function tick(): Promise<void> {
  if (paying.value) return
  if (!(await pay())) emit('open')
}
</script>

<template>
  <div class="flex items-start gap-3 pl-4">
    <span class="pt-3"><TaskCheck :checked="paying" :priority="urgency" :label="$t('payments.payNamed', { name: payment.name })" @toggle="tick" /></span>
    <button type="button" class="press-soft flex min-h-14 min-w-0 flex-1 items-center gap-3 py-3 pr-4 text-left md:rounded-r-xl" @click="emit('open')">
      <span class="flex min-w-0 flex-1 flex-col gap-0.5">
        <span class="break-words">{{ payment.name }}</span>
        <span class="flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-0.5 text-caption text-ink-2">
          <span v-if="due" :class="{ 'font-medium text-danger': payment.overdue }">{{ due }}</span>
          <span>{{ $t('payments.kind') }}<template v-if="account"> · {{ account.name }}</template></span>
        </span>
      </span>
      <MoneyAmount :amount="payment.amount" :currency="payment.currency" class="shrink-0 font-semibold" />
    </button>
  </div>
</template>
