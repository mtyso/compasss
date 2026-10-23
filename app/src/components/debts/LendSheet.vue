<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AmountField from '@/components/AmountField.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { showToast } from '@/components/ui/toast'
import { ApiError } from '@/lib/api'
import { isoDate } from '@/lib/dates'
import { formatMoney } from '@/lib/format'
import { haptics } from '@/lib/haptics'
import { useDebts, type OwedRow } from '@/stores/debts'
import { useMoney } from '@/stores/money'
import { useSettings } from '@/stores/settings'
import AccountPicker from './AccountPicker.vue'

/**
 * «Дал в долг»: to whom, how much, from which account and until when (docs/PLAN.md, D29). With `person`
 * it is «Дать ещё» to someone who already owes, in their currency.
 */
const props = defineProps<{ person?: OwedRow | null }>()
const open = defineModel<boolean>('open', { required: true })

const debts = useDebts()
const money = useMoney()
const settings = useSettings()
const { t } = useI18n()

const name = ref('')
const amount = ref<number | null>(null)
const accountId = ref<number | null>(null)
const dueOn = ref('')
const saving = ref(false)
const error = ref<string | null>(null)

const sources = computed(() =>
  money.activeAccounts.filter((a) => a.type !== 'debt' && a.type !== 'lent' && (!props.person || a.currency === props.person.currency)),
)
const account = computed(() => (accountId.value ? money.accountById.get(accountId.value) : undefined))
const currency = computed(() => props.person?.currency ?? account.value?.currency ?? 'UZS')
const canSave = computed(() => (!!props.person || name.value.trim() !== '') && !!amount.value && !!accountId.value)

watch(open, (value) => {
  if (!value) return
  name.value = ''
  amount.value = null
  error.value = null
  dueOn.value = props.person?.due_on ?? ''
  const from = props.person?.from_account_id
  accountId.value = from && sources.value.some((a) => a.id === from) ? from : (sources.value.find((a) => a.spendable && !a.is_cushion) ?? sources.value[0])?.id ?? null
})

async function save(): Promise<void> {
  if (!canSave.value || saving.value) return
  saving.value = true
  error.value = null
  try {
    const who = props.person?.name ?? name.value.trim()
    await debts.lend({
      ...(props.person ? { lent_account_id: props.person.account_id } : { name: who }),
      account_id: accountId.value!,
      amount: amount.value!,
      due_on: dueOn.value || null,
    })
    haptics.notify('success')
    showToast({ message: t('debts.lentToast', { name: who, amount: formatMoney(amount.value!, currency.value, settings.locale) }), tone: 'success' })
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
  <UiSheet v-model:open="open" :title="person ? `${$t('debts.lendMore')}: ${person.name}` : $t('debts.lend')">
    <div class="flex flex-col gap-5 pt-1">
      <label v-if="!person" class="flex flex-col gap-2">
        <span class="field-label">{{ $t('debts.whom') }}</span>
        <input v-model="name" maxlength="60" :placeholder="$t('debts.whomPlaceholder')" class="field" />
      </label>
      <AmountField v-model="amount" :currency="currency" :label="$t('debts.howMuch')" />
      <AccountPicker v-model="accountId" :accounts="sources" :label="$t('debts.fromAccount')" />
      <label class="flex flex-col gap-2">
        <span class="field-label">{{ $t('debts.dueOn') }}</span>
        <input v-model="dueOn" type="date" :min="isoDate()" class="field w-48" />
        <span class="text-caption text-ink-3">{{ $t('debts.dueOnHint') }}</span>
      </label>
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <UiButton block :disabled="!canSave" :loading="saving" @click="save">{{ $t('debts.record') }}</UiButton>
    </template>
  </UiSheet>
</template>
