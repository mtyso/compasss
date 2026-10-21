<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { Loader2 } from 'lucide-vue-next'
import PageHeader from '@/components/PageHeader.vue'
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
  <div class="flex flex-col gap-5">
    <PageHeader :title="existing ? existing.name : $t('accounts.new')" back="/more/accounts" />

    <label class="flex flex-col gap-1.5">
      <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('accounts.name') }}</span>
      <input v-model="name" maxlength="60" :placeholder="$t('accounts.namePlaceholder')" class="field" />
    </label>

    <section class="flex flex-col gap-1.5">
      <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('accounts.type') }}</span>
      <div class="flex flex-wrap gap-2">
        <button v-for="item in types" :key="item" type="button" class="chip" :class="{ 'chip-on': type === item }" @click="pickType(item)">
          {{ $t(`accounts.types.${item}`) }}
        </button>
      </div>
    </section>

    <section class="flex flex-col gap-1.5">
      <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('accounts.currency') }}</span>
      <div class="flex gap-2">
        <button
          v-for="item in CURRENCIES"
          :key="item"
          type="button"
          class="chip disabled:opacity-40"
          :class="{ 'chip-on': currency === item }"
          :disabled="!!existing && currency !== item"
          @click="currency = item"
        >
          {{ item === 'UZS' ? $t('accounts.som') : $t('accounts.dollar') }}
        </button>
      </div>
      <span v-if="existing" class="px-1 text-xs text-ink-3">{{ $t('accounts.currencyFixed') }}</span>
    </section>

    <label class="flex flex-col gap-1.5">
      <span class="px-1 text-xs font-semibold text-ink-3">{{ existing ? $t('accounts.opening') : $t('accounts.balanceNow') }}</span>
      <input v-model="openingText" inputmode="decimal" placeholder="0" class="field num" />
      <span class="px-1 text-xs text-ink-3">{{ $t('accounts.openingHint') }}</span>
    </label>

    <div class="flex flex-col divide-y divide-line overflow-hidden rounded-2xl bg-surface shadow-sm">
      <label class="flex items-start gap-3 px-4 py-3">
        <input v-model="cushion" type="checkbox" class="mt-0.5 size-5 accent-[var(--c-ink)]" />
        <span class="flex flex-col">
          <span class="text-sm font-medium">{{ $t('accounts.cushion') }}</span>
          <span class="text-xs text-ink-3">{{ $t('accounts.cushionHint') }}</span>
        </span>
      </label>
      <label class="flex items-start gap-3 px-4 py-3" :class="{ 'opacity-40': cushion }">
        <input v-model="spendable" type="checkbox" :disabled="cushion" class="mt-0.5 size-5 accent-[var(--c-ink)]" />
        <span class="flex flex-col">
          <span class="text-sm font-medium">{{ $t('accounts.spendableLabel') }}</span>
          <span class="text-xs text-ink-3">{{ $t('accounts.spendableHint') }}</span>
        </span>
      </label>
    </div>

    <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>

    <button
      type="button"
      class="flex items-center justify-center gap-2 rounded-2xl bg-ink py-3 font-semibold text-surface disabled:opacity-40"
      :disabled="!canSave || saving"
      @click="save"
    >
      <Loader2 v-if="saving" class="size-5 animate-spin" />
      {{ $t('accounts.save') }}
    </button>

    <div v-if="existing" class="flex flex-col items-center gap-2">
      <button type="button" class="py-2 text-sm text-ink-2" @click="toggleArchive">
        {{ existing.archived ? $t('accounts.unarchive') : $t('accounts.archive') }}
      </button>
      <template v-if="!existing.archived">
        <button v-if="!confirmingDelete" type="button" class="py-2 text-sm text-danger" @click="confirmingDelete = true">{{ $t('accounts.delete') }}</button>
        <div v-else class="flex flex-col items-center gap-2 text-center">
          <span class="text-sm text-ink-2">{{ $t('accounts.deleteConfirm') }}</span>
          <div class="flex gap-3">
            <button type="button" class="text-sm text-ink-2" @click="confirmingDelete = false">{{ $t('more.cancel') }}</button>
            <button type="button" class="rounded-xl bg-danger px-3 py-1.5 text-sm font-semibold text-white" @click="remove">{{ $t('accounts.delete') }}</button>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
