<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AmountField from '@/components/AmountField.vue'
import CategoryIcon from '@/components/CategoryIcon.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { showToast } from '@/components/ui/toast'
import { ApiError } from '@/lib/api'
import { monthlyFor, roundUp } from '@/lib/goals'
import { haptics } from '@/lib/haptics'
import { useGoals, type Goal } from '@/stores/goals'
import { useMoney } from '@/stores/money'
import { useSettings } from '@/stores/settings'

/**
 * «Купил»: the purchase is paid from the goal's jar and the goal is done. On installment the rest of the
 * price becomes a debt with its own goal and monthly payment, so the screen shows what that will be.
 */
const props = defineProps<{ goal: Goal }>()
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ bought: [debt: Goal | null] }>()

const goals = useGoals()
const money = useMoney()
const settings = useSettings()
const { t } = useI18n()

const categoryId = ref<number | null>(null)
const amount = ref<number | null>(null)
const price = ref<number | null>(null)
const months = ref<number | null>(null)
const rate = ref<number | null>(null)
const saving = ref(false)
const error = ref<string | null>(null)

const currency = computed(() => props.goal.currency ?? 'UZS')
const onInstallment = computed(() => props.goal.installment !== null)
const categories = computed(() => money.topCategories('expense').filter((c) => !c.archived))
const loan = computed(() => (onInstallment.value && price.value && amount.value ? Math.max(0, price.value - amount.value) : 0))
const payment = computed(() => (loan.value && months.value ? roundUp(monthlyFor(loan.value, months.value, rate.value), currency.value) : null))

watch(open, (value) => {
  if (!value) return
  error.value = null
  amount.value = Math.min(props.goal.saved, props.goal.target_amount ?? props.goal.saved) || props.goal.target_amount
  price.value = props.goal.price
  months.value = props.goal.loan_months
  rate.value = props.goal.rate
  categoryId.value = categoryId.value ?? categories.value.find((c) => c.system_key === 'other_expense')?.id ?? categories.value[0]?.id ?? null
})

const canSave = computed(() => categoryId.value !== null && !!amount.value && (!onInstallment.value || (loan.value > 0 && !!months.value)))

async function save(): Promise<void> {
  if (!canSave.value || saving.value) return
  saving.value = true
  error.value = null
  try {
    const result = await goals.buy(props.goal.id, {
      category_id: categoryId.value!,
      amount: amount.value!,
      ...(onInstallment.value ? { installment: true, price: price.value!, loan_months: months.value!, rate: rate.value } : {}),
    })
    haptics.notify('success')
    showToast({ message: t('goals.bought'), detail: props.goal.title, tone: 'success' })
    open.value = false
    emit('bought', result.debt)
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('goals.failed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UiSheet v-model:open="open" :title="$t('goals.buyTitle')">
    <div class="flex flex-col gap-5 pt-1">
      <AmountField v-model="amount" :currency="currency" :label="onInstallment ? $t('goals.paidDown') : $t('goals.paid')" />

      <section class="flex flex-col gap-2">
        <h3 class="field-label">{{ $t('goals.category') }}</h3>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="category in categories"
            :key="category.id"
            type="button"
            class="chip !py-1.5 !pl-1.5"
            :class="{ 'chip-on': categoryId === category.id }"
            :aria-pressed="categoryId === category.id"
            @click="categoryId = category.id"
          >
            <CategoryIcon :icon="category.icon" :color="category.color" size="sm" class="!size-7" />
            {{ category.names[settings.locale] }}
          </button>
        </div>
      </section>

      <template v-if="onInstallment">
        <AmountField v-model="price" :currency="currency" :label="$t('goals.finalPrice')" />
        <div class="grid grid-cols-2 gap-2">
          <label class="flex flex-col gap-2">
            <span class="field-label">{{ $t('goals.loanMonthsShort') }}</span>
            <input v-model.number="months" type="number" min="1" max="120" inputmode="numeric" class="field num" />
          </label>
          <label class="flex flex-col gap-2">
            <span class="field-label">{{ $t('goals.loanRateShort') }}</span>
            <input v-model.number="rate" type="number" min="0" max="200" step="0.1" inputmode="decimal" placeholder="0" class="field num" />
          </label>
        </div>
        <div v-if="loan" class="card flex flex-col gap-1.5 px-4 py-3 text-sm" data-panel>
          <p class="flex justify-between gap-3"><span class="text-ink-2">{{ $t('goals.becomesDebt') }}</span><MoneyAmount :amount="loan" :currency="currency" class="font-semibold" /></p>
          <p v-if="payment" class="flex justify-between gap-3"><span class="text-ink-2">{{ $t('goals.paymentMonthly') }}</span><MoneyAmount :amount="payment" :currency="currency" class="font-semibold" /></p>
          <p class="text-caption text-ink-3">{{ $t('goals.debtGoalHint') }}</p>
        </div>
      </template>
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <UiButton block :disabled="!canSave" :loading="saving" @click="save">{{ $t('goals.recordPurchase') }}</UiButton>
    </template>
  </UiSheet>
</template>
