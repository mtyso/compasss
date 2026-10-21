<script setup lang="ts">
import { computed } from 'vue'
import CategoryIcon from '@/components/CategoryIcon.vue'
import { formatMoney } from '@/lib/format'
import { useMoney, type Transaction } from '@/stores/money'
import { useSettings } from '@/stores/settings'

const props = defineProps<{ transaction: Transaction }>()

const money = useMoney()
const settings = useSettings()

const category = computed(() => (props.transaction.category_id ? money.categoryById.get(props.transaction.category_id) : undefined))
const root = computed(() => (category.value ? money.rootOf(category.value) : undefined))
const account = computed(() => money.accountById.get(props.transaction.account_id))
const toAccount = computed(() => (props.transaction.to_account_id ? money.accountById.get(props.transaction.to_account_id) : undefined))

const title = computed(() =>
  props.transaction.type === 'transfer'
    ? `${account.value?.name ?? '?'} → ${toAccount.value?.name ?? '?'}`
    : (category.value?.names[settings.locale] ?? ''),
)
const subtitle = computed(() =>
  [props.transaction.note, props.transaction.type === 'transfer' ? null : account.value?.name].filter(Boolean).join(' · '),
)
const sign = computed(() => ({ expense: '−', income: '+', transfer: '' })[props.transaction.type])
const amount = computed(() => formatMoney(props.transaction.amount, props.transaction.currency, settings.locale))
</script>

<template>
  <button type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-left active:bg-bg">
    <CategoryIcon
      :icon="transaction.type === 'transfer' ? 'transfer' : root?.icon"
      :color="transaction.type === 'transfer' ? 'slate' : root?.color"
      size="sm"
    />
    <span class="flex min-w-0 flex-1 flex-col">
      <span class="truncate text-sm font-medium">
        {{ title }}
        <span v-if="transaction.is_one_off" class="ml-1 rounded-md bg-line px-1.5 py-0.5 text-[10px] font-semibold text-ink-2">{{ $t('money.oneOff') }}</span>
      </span>
      <span v-if="subtitle" class="truncate text-xs text-ink-3">{{ subtitle }}</span>
    </span>
    <span class="flex flex-col items-end">
      <span class="num text-sm font-semibold whitespace-nowrap" :class="{ 'text-money': transaction.type === 'income', 'text-ink-2': transaction.type === 'transfer' }">
        {{ sign }}{{ amount.replace(/^−/, '') }}
      </span>
      <span v-if="transaction.currency !== 'UZS' && transaction.type !== 'transfer'" class="num text-[11px] text-ink-3">
        {{ formatMoney(transaction.amount_uzs, 'UZS', settings.locale) }}
      </span>
    </span>
  </button>
</template>
