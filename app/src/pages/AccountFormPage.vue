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
const saving = ref(false)
const confirmingDelete = ref(false)
const error = ref<string | null>(null)

const types: AccountType[] = ['card', 'cash', 'savings', 'deposit', 'other']
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
    openingText.value = amountToInput(account.opening_balance, account.currency)
    spendable.value = account.spendable
    cushion.value = account.is_cushion
  }
})

function pickType(next: AccountType): void {
  type.value = next
  // Savings and deposits are kept out of everyday money by default; a card or cash is in it.
  if (!existing.value) spendable.value = next === 'card' || next === 'cash' || next === 'other'
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
      },
      existing.value?.id,
    )
    router.back()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('quickAdd.failed')
  } finally {
    saving.value = false
  }
}

async function toggleArchive(): Promise<void> {
  if (!existing.value) return
  await money.saveAccount({ archived: !existing.value.archived }, existing.value.id)
  router.back()
}

async function remove(): Promise<void> {
  if (!existing.value) return
  await money.deleteAccount(existing.value.id)
  router.back()
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader :title="existing ? existing.name : $t('accounts.new')" back="/more/accounts" />

    <label class="flex flex-col gap-2">
      <span class="section-title">{{ $t('accounts.name') }}</span>
      <input v-model="name" maxlength="60" :placeholder="$t('accounts.namePlaceholder')" class="field" />
    </label>

    <section class="flex flex-col gap-2">
      <span class="section-title">{{ $t('accounts.type') }}</span>
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
      <span class="section-title">{{ $t('accounts.currency') }}</span>
      <UiSegmented v-if="!existing" v-model="currency" :options="currencyOptions" :label="$t('accounts.currency')" />
      <template v-else>
        <span class="field text-ink-2">{{ currency === 'UZS' ? $t('accounts.som') : $t('accounts.dollar') }}</span>
        <span class="px-1 text-caption text-ink-3">{{ $t('accounts.currencyFixed') }}</span>
      </template>
    </section>

    <label class="flex flex-col gap-2">
      <span class="section-title">{{ existing ? $t('accounts.opening') : $t('accounts.balanceNow') }}</span>
      <span class="field flex items-baseline gap-2 focus-within:shadow-[inset_0_0_0_1.5px_var(--c-ink-3)]">
        <input v-model="openingText" inputmode="decimal" placeholder="0" class="num min-w-0 flex-1 bg-transparent text-lg font-semibold outline-none placeholder:font-normal placeholder:text-ink-3" />
        <span class="text-sm font-medium text-ink-3">{{ currency === 'USD' ? '$' : $t('quickAdd.som') }}</span>
      </span>
      <span class="px-1 text-caption text-ink-3">{{ $t('accounts.openingHint') }}</span>
    </label>

    <div class="group">
      <UiSwitch v-model="cushion" :title="$t('accounts.cushion')" :hint="$t('accounts.cushionHint')" />
      <UiSwitch v-model="spendableShown" :disabled="cushion" :title="$t('accounts.spendableLabel')" :hint="$t('accounts.spendableHint')" />
    </div>

    <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>

    <UiButton size="lg" block :disabled="!canSave" :loading="saving" @click="save">{{ $t('accounts.save') }}</UiButton>

    <div v-if="existing" class="-mt-2 flex flex-col items-center gap-1">
      <UiButton variant="ghost" size="sm" @click="toggleArchive">
        {{ existing.archived ? $t('accounts.unarchive') : $t('accounts.archive') }}
      </UiButton>
      <template v-if="!existing.archived">
        <Transition name="fade" mode="out-in">
          <UiButton v-if="!confirmingDelete" variant="ghost" size="sm" class="!text-danger" @click="confirmingDelete = true">{{ $t('accounts.delete') }}</UiButton>
          <div v-else class="card flex w-full flex-col gap-3 p-4 text-center">
            <span class="text-sm text-ink-2">{{ $t('accounts.deleteConfirm') }}</span>
            <div class="flex gap-2">
              <UiButton variant="secondary" size="sm" class="flex-1" @click="confirmingDelete = false">{{ $t('more.cancel') }}</UiButton>
              <UiButton variant="danger" size="sm" class="flex-1" @click="remove">{{ $t('accounts.delete') }}</UiButton>
            </div>
          </div>
        </Transition>
      </template>
    </div>
  </div>
</template>
