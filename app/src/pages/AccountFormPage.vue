<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import AccountIcon from '@/components/money/AccountIcon.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import UiSwitch from '@/components/ui/UiSwitch.vue'
import { ApiError } from '@/lib/api'
import type { Currency } from '@/lib/format'
import { groupDigits, groupInput } from '@/lib/digits'
import { amountToInput, CURRENCIES, EXPONENT } from '@/lib/money'
import { useMoney, type AccountType } from '@/stores/money'

const money = useMoney()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const id = computed(() => (route.params.id ? Number(route.params.id) : null))
const existing = computed(() => (id.value ? money.accountById.get(id.value) : undefined))

const name = ref('')
const type = ref<AccountType>('card')
const currency = ref<Currency>('UZS')
const openingText = ref('')
const spendable = ref(true)
const cushion = ref(false)
/** Money lent: the day it was promised back (docs/PLAN.md, D29). */
const dueOn = ref('')
const saving = ref(false)
const error = ref<string | null>(null)

const types: AccountType[] = ['card', 'cash', 'savings', 'deposit', 'debt', 'lent', 'other']
const currencyOptions = computed(() => CURRENCIES.map((value) => ({ value, label: value === 'UZS' ? t('accounts.som') : t('accounts.dollar') })))
/** «Деньги на жизнь» is off and locked while the account is the safety cushion. */
const spendableShown = computed({
  get: () => spendable.value && !cushion.value,
  set: (value: boolean) => (spendable.value = value),
})

onMounted(async () => {
  if (!money.accounts.length) await money.loadAccounts()
  const account = existing.value
  if (account) {
    name.value = account.name
    type.value = account.type
    currency.value = account.currency
    openingText.value = groupDigits(amountToInput(account.opening_balance, account.currency))
    spendable.value = account.spendable
    cushion.value = account.is_cushion
    dueOn.value = account.due_on ?? ''
  }
})

function pickType(next: AccountType): void {
  type.value = next
  // Savings and deposits are kept out of everyday money by default; a card or cash is in it.
  if (!existing.value) spendable.value = next === 'card' || next === 'cash' || next === 'other'
  if (next === 'debt' || next === 'lent') cushion.value = false
}

/** Opening balance may be negative (a credit card already in debt), unlike an operation amount. */
const opening = computed(() => {
  const text = openingText.value.trim().replace(/[\s  ]/g, '').replace(',', '.')
  if (text === '') return 0
  if (!/^-?\d+(\.\d+)?$/.test(text)) return null
  return Math.round(Number(text) * 10 ** EXPONENT[currency.value])
})
const canSave = computed(() => name.value.trim() !== '' && opening.value !== null)

async function save(): Promise<void> {
  if (!canSave.value) return
  saving.value = true
  error.value = null
  try {
    await money.saveAccount(
      {
        name: name.value.trim(),
        type: type.value,
        ...(existing.value ? {} : { currency: currency.value }),
        opening_balance: opening.value!,
        spendable: spendable.value && !cushion.value,
        is_cushion: cushion.value,
        due_on: type.value === 'lent' ? dueOn.value || null : null,
      },
      existing.value?.id,
    )
    if (window.history.state?.back) router.back()
    else void router.replace(back.value)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('quickAdd.failed')
  } finally {
    saving.value = false
  }
}

/** Back to where the form was opened: an account's own screen, or the list for a new one. */
const back = computed(() => (id.value ? `/more/accounts/${id.value}` : '/more/accounts'))
</script>

<template>
  <div class="page">
    <PageHeader :title="existing ? $t('details.editAccount') : $t('accounts.new')" :back="back" />

    <label class="flex flex-col gap-2">
      <span class="field-label">{{ $t('accounts.name') }}</span>
      <input v-model="name" maxlength="60" :placeholder="$t('accounts.namePlaceholder')" class="field" />
    </label>

    <section class="flex flex-col gap-2">
      <span class="field-label">{{ $t('accounts.type') }}</span>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="item in types"
          :key="item"
          type="button"
          class="chip !pl-1.5"
          :class="{ 'chip-on': type === item }"
          :aria-pressed="type === item"
          @click="pickType(item)"
        >
          <AccountIcon :type="item" class="!size-7 !rounded-full" :class="{ '!bg-white/15 !text-surface': type === item }" />
          {{ $t(`accounts.types.${item}`) }}
        </button>
      </div>
    </section>

    <section class="flex flex-col gap-2">
      <span class="field-label">{{ $t('accounts.currency') }}</span>
      <UiSegmented v-if="!existing" v-model="currency" :options="currencyOptions" :label="$t('accounts.currency')" />
      <template v-else>
        <span class="field text-ink-2">{{ currency === 'UZS' ? $t('accounts.som') : $t('accounts.dollar') }}</span>
        <span class="text-caption text-ink-3">{{ $t('accounts.currencyFixed') }}</span>
      </template>
    </section>

    <label class="flex flex-col gap-2">
      <span class="field-label">{{ existing ? $t('accounts.opening') : $t('accounts.balanceNow') }}</span>
      <span class="field flex items-baseline gap-2 focus-within:shadow-[inset_0_0_0_1.5px_var(--c-ink-3)]">
        <input :value="openingText" inputmode="decimal" autocomplete="off" placeholder="0" class="num min-w-0 flex-1 bg-transparent text-lg font-semibold outline-none placeholder:font-normal placeholder:text-ink-3" @input="groupInput($event, (value) => (openingText = value))" />
        <span class="text-sm font-medium text-ink-3">{{ currency === 'USD' ? '$' : $t('quickAdd.som') }}</span>
      </span>
      <span class="text-caption text-ink-3">{{ $t('accounts.openingHint') }}</span>
    </label>

    <label v-if="type === 'lent'" class="flex flex-col gap-2">
      <span class="field-label">{{ $t('accounts.dueOn') }}</span>
      <input v-model="dueOn" type="date" class="field w-48" />
      <span class="text-caption text-ink-3">{{ $t('debts.dueOnHint') }}</span>
    </label>

    <div v-if="type !== 'debt' && type !== 'lent'" class="group">
      <UiSwitch v-model="cushion" :title="$t('accounts.cushion')" :hint="$t('accounts.cushionHint')" />
      <UiSwitch v-model="spendableShown" :disabled="cushion" :title="$t('accounts.spendableLabel')" :hint="$t('accounts.spendableHint')" />
    </div>

    <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>

    <UiButton size="lg" block :disabled="!canSave" :loading="saving" @click="save">{{ $t('accounts.save') }}</UiButton>
  </div>
</template>
