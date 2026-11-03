<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Pencil, Trash2 } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiFact from '@/components/ui/UiFact.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { haptics } from '@/lib/haptics'
import { isoDate, isoTime } from '@/lib/dates'
import { formatDayHeading, formatDayMonth, formatMoney } from '@/lib/format'
import { useMoney, useQuickAdd, type Category } from '@/stores/money'
import { usePlan } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

/**
 * An operation, read-only: what a tap on it in a list opens (docs/PLAN.md, D42). Everything it holds,
 * then «Изменить» for the form and «Удалить» (the toast offers «Вернуть»). Mounted once in AppShell.
 */
const quick = useQuickAdd()
const money = useMoney()
const plan = usePlan()
const settings = useSettings()
const { t } = useI18n()

const busy = ref(false)
const error = ref<string | null>(null)

const tx = computed(() => quick.viewing)
const category = computed(() => (tx.value?.category_id ? money.categoryById.get(tx.value.category_id) : undefined))
const root = computed(() => (category.value ? money.rootOf(category.value) : undefined))
const account = computed(() => (tx.value ? money.accountById.get(tx.value.account_id) : undefined))
const toAccount = computed(() => (tx.value?.to_account_id ? money.accountById.get(tx.value.to_account_id) : undefined))
const transfer = computed(() => tx.value?.type === 'transfer')
const payment = computed(() => (tx.value?.recurring_payment_id ? plan.payments.find((p) => p.id === tx.value!.recurring_payment_id) : undefined))

watch(
  () => quick.viewOpen,
  (open) => {
    if (!open) return
    error.value = null
    void money.ensureLoaded().catch(() => {})
    // The payment it paid is named by the list of payments, which this screen may not have loaded.
    if (tx.value?.recurring_payment_id && !plan.payments.length) void plan.loadPayments().catch(() => {})
  },
)

const name = (item: Category) => item.names[settings.locale]

/** «Кафе и рестораны · Кофе», the main category alone, or «Humo → Наличные» for a transfer. */
const what = computed(() => {
  if (transfer.value) return `${account.value?.name ?? '?'} → ${toAccount.value?.name ?? '?'}`
  if (!category.value) return ''
  return root.value && root.value.id !== category.value.id ? `${name(root.value)} · ${name(category.value)}` : name(category.value)
})

/** A year that is not this one goes after the date: «31 октября 2025». */
function withYear(day: string, text: string): string {
  return day.slice(0, 4) === isoDate().slice(0, 4) ? text : `${text} ${day.slice(0, 4)}`
}

/** «Пятница, 31 октября, 14:35». */
const when = computed(() => {
  if (!tx.value) return ''
  const at = new Date(tx.value.occurred_at)
  return `${withYear(isoDate(at), formatDayHeading(at, settings.locale))}, ${isoTime(at)}`
})

/** «31 октября, 14:40, через бота»: when it was written down and where, for an operation entered later too. */
const recorded = computed(() => {
  if (!tx.value?.created_at) return null
  const at = new Date(tx.value.created_at)
  return `${withYear(isoDate(at), formatDayMonth(isoDate(at), settings.locale))}, ${isoTime(at)}, ${t(`quickAdd.via.${tx.value.source}`)}`
})

/** A dollar operation: what it came to in som, at the day's rate the server used. */
const inSom = computed(() => (tx.value && tx.value.currency !== 'UZS' && !transfer.value ? tx.value.amount_uzs : null))
const rate = computed(() => {
  const value = Number(tx.value?.rate)
  return value > 1 ? formatMoney(Math.round(value), 'UZS', settings.locale) : null
})

function edit(): void {
  haptics.selection()
  quick.edit()
}

async function remove(): Promise<void> {
  const current = tx.value
  if (!current || busy.value) return
  busy.value = true
  error.value = null
  try {
    await money.deleteTransaction(current.id)
    quick.viewOpen = false
    quick.deletedId = current.id
  } catch {
    haptics.notify('error')
    error.value = t('quickAdd.failed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <UiSheet v-model:open="quick.viewOpen" :title="tx ? $t(`quickAdd.types.${tx.type}`) : ''">
    <div v-if="tx" class="flex flex-col gap-4 pt-1">
      <!-- What it was and how much: the line the eye looks for. -->
      <section class="flex items-center gap-4">
        <CategoryIcon :icon="transfer ? 'transfer' : root?.icon" :color="transfer ? 'slate' : root?.color" size="lg" />
        <div class="flex min-w-0 flex-1 flex-col gap-1">
          <MoneyAmount
            :amount="tx.amount"
            :currency="tx.currency"
            :sign="tx.type === 'expense' ? 'expense' : tx.type === 'income' ? 'always' : 'auto'"
            class="text-[2rem] leading-none font-semibold tracking-[-0.03em]"
            :class="{ 'text-money': tx.type === 'income' }"
          />
          <span class="text-[0.9375rem] text-ink-2">{{ what }}</span>
        </div>
      </section>

      <dl class="facts">
        <template v-if="transfer">
          <UiFact :label="$t('quickAdd.from')">{{ account?.name ?? '—' }}</UiFact>
          <UiFact :label="$t('quickAdd.to')">{{ toAccount?.name ?? '—' }}</UiFact>
          <UiFact v-if="tx.to_amount && toAccount" :label="$t('details.received')">
            <MoneyAmount :amount="tx.to_amount" :currency="toAccount.currency" />
          </UiFact>
        </template>
        <template v-else>
          <UiFact :label="$t('quickAdd.account')">{{ account?.name ?? '—' }}</UiFact>
          <UiFact v-if="inSom !== null" :label="$t('details.inSom')">
            <MoneyAmount :amount="inSom" currency="UZS" />
            <template v-if="rate" #hint>{{ $t('details.rate', { rate }) }}</template>
          </UiFact>
        </template>
        <UiFact :label="$t('quickAdd.date')">{{ when }}</UiFact>
        <UiFact v-if="tx.note" :label="$t('quickAdd.notePlaceholder')">{{ tx.note }}</UiFact>
        <UiFact v-if="tx.is_one_off" :label="$t('quickAdd.oneOff')">
          {{ $t('details.yes') }}
          <template #hint>{{ $t('details.oneOffHint') }}</template>
        </UiFact>
        <UiFact v-if="tx.recurring_payment_id" :label="$t('payments.kind')">{{ payment?.name ?? '…' }}</UiFact>
        <UiFact v-if="recorded" :label="$t('details.recorded')">{{ recorded }}</UiFact>
      </dl>
    </div>

    <template v-if="tx" #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <div class="flex gap-2">
        <UiButton variant="danger-soft" class="!w-12 !px-0" :aria-label="$t('quickAdd.delete')" :title="$t('quickAdd.delete')" :loading="busy" @click="remove">
          <Trash2 class="size-5" />
        </UiButton>
        <UiButton variant="secondary" class="flex-1" :disabled="busy" @click="edit">
          <template #icon><Pencil class="size-[18px]" :stroke-width="2.2" /></template>
          {{ $t('details.edit') }}
        </UiButton>
      </div>
    </template>
  </UiSheet>
</template>
