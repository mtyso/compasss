<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiSpinner from '@/components/ui/UiSpinner.vue'
import { haptics } from '@/lib/haptics'
import { isoDate, shiftDate } from '@/lib/dates'
import { formatDayMonth } from '@/lib/format'
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
    haptics.notify('success')
    emit('paid')
  } catch {
    haptics.notify('error')
    failed.value = true
  } finally {
    paying.value = false
  }
}
</script>

<template>
  <div class="flex items-center gap-1 pr-3">
    <button type="button" class="press-soft flex min-h-15 min-w-0 flex-1 items-center gap-3 py-2.5 pl-4 text-left md:rounded-l-xl" @click="emit('open')">
      <CategoryIcon :icon="root?.icon" :color="root?.color" size="sm" />
      <span class="flex min-w-0 flex-1 flex-col gap-0.5">
        <span class="truncate font-medium">{{ payment.name }}</span>
        <span class="flex min-w-0 items-center gap-1 text-caption">
          <span class="shrink-0" :class="due.danger ? 'font-medium text-danger' : 'text-ink-3'">{{ due.text }}<template v-if="account">,</template></span>
          <span v-if="account" class="truncate text-ink-3">{{ account.name }}</span>
        </span>
      </span>
      <MoneyAmount :amount="payment.amount" :currency="payment.currency" class="pr-2 font-semibold" />
    </button>
    <button
      type="button"
      class="pay press grid size-10 shrink-0 place-items-center rounded-full"
      :class="failed ? 'bg-danger-soft text-danger' : 'bg-surface-2 text-ink-2'"
      :aria-label="$t('payments.payNamed', { name: payment.name })"
      :title="$t('payments.pay')"
      :disabled="paying"
      @click="pay"
    >
      <UiSpinner v-if="paying" :size="16" />
      <Check v-else class="size-[18px]" :stroke-width="2.4" />
    </button>
  </div>
</template>

<style scoped>
.pay:active:not(:disabled) {
  background: var(--c-money);
  color: #fff;
}
</style>
