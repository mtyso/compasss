<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AmountField from '@/components/AmountField.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { showToast } from '@/components/ui/toast'
import { ApiError } from '@/lib/api'
import { isoDate, shiftDate } from '@/lib/dates'
import type { Currency } from '@/lib/format'
import { haptics } from '@/lib/haptics'
import { CURRENCIES } from '@/lib/money'
import { useDebts } from '@/stores/debts'
import { useMoney } from '@/stores/money'
import AccountPicker from './AccountPicker.vue'

/**
 * «Взял в долг» (docs/PLAN.md, D29): money that came onto an account now, or an old debt that is already
 * spent. A payment, a date or a rate makes a plan: the limit then sets the payment aside every month.
 */
const open = defineModel<boolean>('open', { required: true })

const debts = useDebts()
const money = useMoney()
const { t } = useI18n()

const name = ref('')
const amount = ref<number | null>(null)
/** The account the money came to; null for an old debt. */
const accountId = ref<number | null>(null)
const old = ref(false)
const oldCurrency = ref<Currency>('UZS')
const payment = ref<number | null>(null)
const payBy = ref('')
const rate = ref<number | null>(null)
const saving = ref(false)
const error = ref<string | null>(null)

const targets = computed(() => money.activeAccounts.filter((a) => a.type !== 'debt' && a.type !== 'lent'))
const currency = computed<Currency>(() => (old.value ? oldCurrency.value : (money.accountById.get(accountId.value ?? 0)?.currency ?? 'UZS')))
const currencyOptions = computed(() => CURRENCIES.map((value) => ({ value, label: value === 'UZS' ? t('accounts.som') : t('accounts.dollar') })))
const canSave = computed(() => name.value.trim() !== '' && !!amount.value && (old.value || !!accountId.value))

watch(open, (value) => {
  if (!value) return
  name.value = ''
  amount.value = null
  old.value = false
  oldCurrency.value = 'UZS'
  payment.value = null
  payBy.value = ''
  rate.value = null
  error.value = null
  accountId.value = (targets.value.find((a) => a.spendable && !a.is_cushion) ?? targets.value[0])?.id ?? null
})

function pickOld(): void {
  haptics.selection()
  old.value = true
  accountId.value = null
}

watch(accountId, (id) => {
  if (id !== null) old.value = false
})

async function save(): Promise<void> {
  if (!canSave.value || saving.value) return
  saving.value = true
  error.value = null
  try {
    await debts.borrow({
      name: name.value.trim(),
      ...(old.value ? { currency: oldCurrency.value } : { account_id: accountId.value }),
      amount: amount.value!,
      monthly_amount: payment.value || null,
      deadline: payBy.value || null,
      rate: rate.value || null,
    })
    haptics.notify('success')
    showToast({ message: t('debts.borrowedToast', { name: name.value.trim() }), tone: 'success' })
    open.value = false
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('quickAdd.failed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UiSheet v-model:open="open" :title="$t('debts.borrow')">
    <div class="flex flex-col gap-5 pt-1">
      <label class="flex flex-col gap-2">
        <span class="field-label">{{ $t('debts.lender') }}</span>
        <input v-model="name" maxlength="60" :placeholder="$t('debts.lenderPlaceholder')" class="field" />
      </label>
      <AccountPicker v-model="accountId" :accounts="targets" :label="$t('debts.toAccount')">
        <button type="button" class="chip shrink-0" :class="{ 'chip-on': old }" :aria-pressed="old" @click="pickOld">{{ $t('debts.alreadySpent') }}</button>
      </AccountPicker>
      <template v-if="old">
        <p class="-mt-3 text-caption text-ink-3">{{ $t('debts.alreadySpentHint') }}</p>
        <UiSegmented v-model="oldCurrency" :options="currencyOptions" :label="$t('accounts.currency')" />
      </template>
      <AmountField v-model="amount" :currency="currency" :label="$t('debts.howMuch')" />

      <section class="flex flex-col gap-4 border-t border-line pt-5">
        <div class="flex flex-col gap-1">
          <h3 class="font-medium">{{ $t('debts.planTitle') }}</h3>
          <p class="text-caption text-ink-3">{{ $t('debts.planHint') }}</p>
        </div>
        <AmountField v-model="payment" :currency="currency" :label="$t('debts.payment')" />
        <div class="flex flex-wrap gap-4">
          <label class="flex flex-col gap-2">
            <span class="field-label">{{ $t('debts.payBy') }}</span>
            <input v-model="payBy" type="date" :min="shiftDate(isoDate(), 1)" class="field w-48" />
          </label>
          <label class="flex flex-col gap-2">
            <span class="field-label">{{ $t('debts.ratePercent') }}</span>
            <input v-model.number="rate" type="number" min="0" max="200" step="0.1" inputmode="decimal" class="field num w-28" />
          </label>
        </div>
      </section>
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <UiButton block :disabled="!canSave" :loading="saving" @click="save">{{ $t('debts.record') }}</UiButton>
    </template>
  </UiSheet>
</template>
