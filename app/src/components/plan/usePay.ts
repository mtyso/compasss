import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { showToast } from '@/components/ui/toast'
import { formatDayMonth } from '@/lib/format'
import { haptics } from '@/lib/haptics'
import { useMoney } from '@/stores/money'
import { usePlan, type RecurringPayment } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

/**
 * «Оплачено» in one tap, shared by the payment rows of «Сегодня», «Дела» and «Платежи». One tap works when
 * the payment knows its account and they share a currency; otherwise `pay` returns false and the caller
 * opens the form (PaySheet).
 */
export function usePay(payment: () => RecurringPayment) {
  const money = useMoney()
  const plan = usePlan()
  const settings = useSettings()
  const { t } = useI18n()
  const paying = ref(false)
  const failed = ref(false)

  const account = computed(() => (payment().account_id ? money.accountById.get(payment().account_id!) : undefined))
  const oneTap = computed(() => !!account.value && account.value.currency === payment().currency)

  /** @returns false when the form is needed instead */
  async function pay(): Promise<boolean> {
    if (!oneTap.value) return false
    const current = payment()
    paying.value = true
    failed.value = false
    try {
      const { payment: next } = await plan.pay(current)
      haptics.notify('success')
      showToast({
        message: t('payments.paidToast', { name: current.name }),
        detail: t('payments.nextOn', { date: formatDayMonth(next.next_due_on, settings.locale) }),
        tone: 'success',
      })
    } catch {
      haptics.notify('error')
      failed.value = true
    } finally {
      paying.value = false
    }
    return true
  }

  return { account, oneTap, paying, failed, pay }
}
