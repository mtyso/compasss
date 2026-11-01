<script setup lang="ts">
import { computed } from 'vue'
import CategoryIcon from '@/components/CategoryIcon.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import { isoDate, isoTime } from '@/lib/dates'
import { formatDayMonthShort, formatMoney } from '@/lib/format'
import { useMoney, type Transaction } from '@/stores/money'
import { useSettings } from '@/stores/settings'

/** `dated`: the row stands outside a day's group (the month's biggest), so its time carries the date too. */
const props = defineProps<{ transaction: Transaction; dated?: boolean }>()

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

/** «14:32», or «29 окт, 14:32» on a dated row; after the sum in som when the operation was in another currency. */
const when = computed(() => {
  const at = new Date(props.transaction.occurred_at)
  const time = props.dated ? `${formatDayMonthShort(isoDate(at), settings.locale)}, ${isoTime(at)}` : isoTime(at)
  const inSom = props.transaction.currency !== 'UZS' && props.transaction.type !== 'transfer'
  return inSom ? `${formatMoney(props.transaction.amount_uzs, 'UZS', settings.locale)} · ${time}` : time
})
</script>

<template>
  <button type="button" class="press-soft flex min-h-15 w-full items-center gap-3 px-4 py-2.5 text-left">
    <CategoryIcon
      :icon="transaction.type === 'transfer' ? 'transfer' : root?.icon"
      :color="transaction.type === 'transfer' ? 'slate' : root?.color"
      size="sm"
    />
    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
      <span class="flex min-w-0 items-center gap-1.5">
        <span class="truncate font-medium">{{ title }}</span>
        <span v-if="transaction.is_one_off" class="shrink-0 rounded-md bg-surface-2 px-1.5 py-px text-[0.625rem] font-semibold text-ink-2">{{ $t('money.oneOff') }}</span>
      </span>
      <span v-if="subtitle" class="truncate text-caption text-ink-3">{{ subtitle }}</span>
    </span>
    <span class="flex flex-col items-end gap-0.5">
      <MoneyAmount
        :amount="transaction.amount"
        :currency="transaction.currency"
        :sign="transaction.type === 'expense' ? 'expense' : transaction.type === 'income' ? 'always' : 'auto'"
        class="font-semibold"
        :class="{ 'text-money': transaction.type === 'income', 'text-ink-2': transaction.type === 'transfer' }"
      />
      <time class="num text-caption whitespace-nowrap text-ink-3" :datetime="transaction.occurred_at">{{ when }}</time>
    </span>
  </button>
</template>
