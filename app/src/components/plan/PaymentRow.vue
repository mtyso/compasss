<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, Loader2 } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import { isoDate, shiftDate } from '@/lib/dates'
import { formatDayMonth, formatMoney } from '@/lib/format'
import { useMoney } from '@/stores/money'
import { usePlan, type RecurringPayment } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

const props = defineProps<{ payment: RecurringPayment }>()
/** `open` is a tap on the row itself; `edit` asks for the form when one tap cannot pay. */
const emit = defineEmits<{ paid: []; open: []; edit: [] }>()

const money = useMoney()
const plan = usePlan()
const settings = useSettings()
const { t } = useI18n()
const paying = ref(false)
const failed = ref(false)

const category = computed(() => money.categoryById.get(props.payment.category_id))
const root = computed(() => (category.value ? money.rootOf(category.value) : undefined))
const account = computed(() => (props.payment.account_id ? money.accountById.get(props.payment.account_id) : undefined))
// One tap works when the payment knows its account and they share a currency; otherwise the form asks.
const oneTap = computed(() => !!account.value && account.value.currency === props.payment.currency)

const due = computed(() => {
  const today = isoDate()
  if (props.payment.overdue) return { text: t('payments.overdue'), danger: true }
  if (props.payment.next_due_on === today) return { text: t('payments.dueToday'), danger: true }
  if (props.payment.next_due_on === shiftDate(today, 1)) return { text: t('payments.dueTomorrow'), danger: false }
  return { text: formatDayMonth(props.payment.next_due_on, settings.locale), danger: false }
})

async function pay(): Promise<void> {
  if (!oneTap.value) {
    emit('edit')
    return
  }
  paying.value = true
  failed.value = false
  try {
    await plan.pay(props.payment)
    emit('paid')
  } catch {
    failed.value = true
  } finally {
    paying.value = false
  }
}
</script>

<template>
  <div class="flex items-center gap-3 px-4 py-2.5">
    <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" @click="emit('open')">
      <CategoryIcon :icon="root?.icon" :color="root?.color" size="sm" />
      <span class="flex min-w-0 flex-1 flex-col">
        <span class="truncate text-sm font-medium">{{ payment.name }}</span>
        <span class="text-xs" :class="due.danger ? 'text-danger' : 'text-ink-3'">
          {{ due.text }}<template v-if="account"> · {{ account.name }}</template>
        </span>
      </span>
      <span class="num text-sm font-semibold whitespace-nowrap">{{ formatMoney(payment.amount, payment.currency, settings.locale) }}</span>
    </button>
    <button
      type="button"
      class="grid size-9 shrink-0 place-items-center rounded-full border transition-colors"
      :class="failed ? 'border-danger text-danger' : 'border-line text-ink-2 active:bg-money active:text-white'"
      :aria-label="$t('payments.payNamed', { name: payment.name })"
      :title="$t('payments.pay')"
      :disabled="paying"
      @click="pay"
    >
      <Loader2 v-if="paying" class="size-4 animate-spin" />
      <Check v-else class="size-4" />
    </button>
  </div>
</template>
