<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Archive, ArchiveRestore, Pencil, ReceiptText } from 'lucide-vue-next'
import AccountIcon from '@/components/money/AccountIcon.vue'
import TransactionRow from '@/components/money/TransactionRow.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiFact from '@/components/ui/UiFact.vue'
import { api } from '@/lib/api'
import { dateAtNoon, isoDate } from '@/lib/dates'
import { formatDayHeading, formatDayMonth, formatMoney } from '@/lib/format'
import { haptics } from '@/lib/haptics'
import { toUzs } from '@/lib/money'
import { useMoney, useQuickAdd, type Transaction } from '@/stores/money'
import { useSettings } from '@/stores/settings'

/**
 * One account, read-only (docs/PLAN.md, D42): the balance, what kind of money it is, and its
 * operations day by day. «Изменить» opens the form; archiving and deleting are here as well.
 */
const route = useRoute()
const router = useRouter()
const money = useMoney()
const quick = useQuickAdd()
const settings = useSettings()
const { t } = useI18n()

const id = computed(() => Number(route.params.id))
const account = computed(() => money.accountById.get(id.value))
const loaded = ref(false)

type Page = { data: Transaction[]; next_cursor: string | null }
const transactions = ref<Transaction[]>([])
const nextCursor = ref<string | null>(null)
const opsLoaded = ref(false)
const opsFailed = ref(false)
const loadingMore = ref(false)
const busy = ref<'archive' | 'delete' | null>(null)
const confirmingDelete = ref(false)

function operationsUrl(cursor?: string): string {
  const query = new URLSearchParams({ account_id: String(id.value) })
  if (cursor) query.set('cursor', cursor)
  return `/transactions?${query}`
}

async function loadOperations(): Promise<void> {
  try {
    const page = await api<Page>(operationsUrl())
    transactions.value = page.data
    nextCursor.value = page.next_cursor
    opsFailed.value = false
  } catch {
    opsFailed.value = true
  } finally {
    opsLoaded.value = true
  }
}

async function loadMore(): Promise<void> {
  if (!nextCursor.value) return
  loadingMore.value = true
  try {
    const page = await api<Page>(operationsUrl(nextCursor.value))
    transactions.value.push(...page.data)
    nextCursor.value = page.next_cursor
  } finally {
    loadingMore.value = false
  }
}

onMounted(async () => {
  await Promise.all([money.ensureLoaded().catch(() => {}), loadOperations()])
  loaded.value = true
  // A link to an account that is gone (deleted elsewhere, or a typo): the list of accounts instead.
  if (!account.value) void router.replace({ name: 'accounts' })
})
watch(() => money.revision, loadOperations)

/** What the account's money is for: «Карта · деньги на жизнь», «Подушка безопасности». */
const kind = computed(() => {
  const a = account.value
  if (!a) return ''
  return a.is_cushion ? `${t(`accounts.types.${a.type}`)} · ${t('accounts.cushion')}` : t(`accounts.types.${a.type}`)
})

const inSom = computed(() => {
  const a = account.value
  const rate = a ? money.rates[a.currency] : undefined
  return a && a.currency !== 'UZS' && rate ? toUzs(a.balance, a.currency, rate) : null
})

/** Debts and money lent are not spending money either way, so the switch is not shown for them. */
const spendableShown = computed(() => !!account.value && account.value.type !== 'debt' && account.value.type !== 'lent' && !account.value.is_cushion)

/** How an operation changed this account: out of it is a minus, into it a plus, in its own currency. */
function change(tx: Transaction): number {
  if (tx.type === 'expense') return -tx.amount
  if (tx.type === 'income') return tx.amount
  return tx.to_account_id === id.value && tx.account_id !== id.value ? (tx.to_amount ?? tx.amount) : -tx.amount
}

const days = computed(() => {
  const groups = new Map<string, Transaction[]>()
  for (const tx of transactions.value) {
    const day = isoDate(new Date(tx.occurred_at))
    groups.set(day, [...(groups.get(day) ?? []), tx])
  }
  return [...groups.entries()].map(([day, items]) => ({
    day,
    heading: withYear(day, formatDayHeading(dateAtNoon(day), settings.locale)),
    net: items.reduce((sum, tx) => sum + change(tx), 0),
    items,
  }))
})

function withYear(day: string, text: string): string {
  return day.slice(0, 4) === isoDate().slice(0, 4) ? text : `${text} ${day.slice(0, 4)}`
}

async function toggleArchive(): Promise<void> {
  const a = account.value
  if (!a || busy.value) return
  busy.value = 'archive'
  try {
    await money.saveAccount({ archived: !a.archived }, a.id)
    haptics.selection()
  } finally {
    busy.value = null
  }
}

async function remove(): Promise<void> {
  const a = account.value
  if (!a || busy.value) return
  busy.value = 'delete'
  try {
    await money.deleteAccount(a.id)
    router.back()
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <div class="page page-wide">
    <ScreenHeader :title="account?.name ?? $t('accounts.title')" back="/more/accounts">
      <template v-if="account" #trailing>
        <UiButton variant="secondary" size="sm" :to="{ name: 'account-edit', params: { id: account.id } }">
          <template #icon><Pencil class="size-4" :stroke-width="2.2" /></template>
          {{ $t('details.edit') }}
        </UiButton>
      </template>
    </ScreenHeader>

    <template v-if="!loaded && !account">
      <div class="skeleton h-20 rounded-card" />
      <div class="skeleton h-40 rounded-card" />
    </template>

    <div v-else-if="account" class="page-columns">
      <div class="page-col">
        <!-- The balance, as on the list it was tapped in, and what kind of money it is. -->
        <section class="flex flex-col gap-1.5">
          <span class="flex items-center gap-2.5 text-[0.9375rem] font-medium text-ink-2">
            <AccountIcon :type="account.type" :cushion="account.is_cushion" class="!size-7" />
            {{ kind }}
            <span v-if="account.archived" class="rounded-md bg-surface-2 px-1.5 py-px text-[0.6875rem] font-semibold text-ink-2">{{ $t('accounts.archived') }}</span>
          </span>
          <MoneyAmount
            :amount="account.balance"
            :currency="account.currency"
            animate
            class="pt-1 text-[2.25rem] leading-none font-semibold tracking-[-0.03em]"
            :class="{ 'text-danger': account.balance < 0 }"
          />
          <span v-if="inSom !== null" class="num pt-1 text-caption text-ink-3">≈ {{ formatMoney(inSom, 'UZS', settings.locale) }}</span>
        </section>

        <dl class="facts">
          <UiFact :label="$t('accounts.currency')">{{ account.currency === 'UZS' ? $t('accounts.som') : $t('accounts.dollar') }}</UiFact>
          <UiFact :label="$t('accounts.opening')"><MoneyAmount :amount="account.opening_balance" :currency="account.currency" /></UiFact>
          <UiFact v-if="spendableShown" :label="$t('accounts.spendableLabel')">
            {{ account.spendable ? $t('details.yes') : $t('details.no') }}
            <template #hint>{{ account.spendable ? $t('details.spendableYes') : $t('details.spendableNo') }}</template>
          </UiFact>
          <UiFact v-if="account.type === 'lent'" :label="$t('accounts.dueOn')">{{ account.due_on ? formatDayMonth(account.due_on, settings.locale) : '—' }}</UiFact>
        </dl>

        <div class="flex flex-col items-center gap-1 max-xl:order-last">
          <UiButton variant="ghost" size="sm" :loading="busy === 'archive'" @click="toggleArchive">
            <template #icon><ArchiveRestore v-if="account.archived" class="size-4" /><Archive v-else class="size-4" /></template>
            {{ account.archived ? $t('accounts.unarchive') : $t('accounts.archive') }}
          </UiButton>
          <template v-if="!account.archived">
            <Transition name="fade" mode="out-in">
              <UiButton v-if="!confirmingDelete" variant="ghost" size="sm" class="!text-danger" @click="confirmingDelete = true">{{ $t('accounts.delete') }}</UiButton>
              <div v-else class="card flex w-full flex-col gap-3 p-4 text-center">
                <span class="text-sm text-ink-2">{{ $t('accounts.deleteConfirm') }}</span>
                <div class="flex gap-2">
                  <UiButton variant="secondary" size="sm" class="flex-1 !bg-bg" @click="confirmingDelete = false">{{ $t('more.cancel') }}</UiButton>
                  <UiButton variant="danger" size="sm" class="flex-1" :loading="busy === 'delete'" @click="remove">{{ $t('accounts.delete') }}</UiButton>
                </div>
              </div>
            </Transition>
          </template>
        </div>
      </div>

      <!-- Its operations, newest first; a tap opens one the same way as on «Деньги». -->
      <div class="page-col">
        <section class="flex flex-col gap-3">
          <h2 class="section-title">{{ $t('money.operations') }}</h2>
          <div v-if="!opsLoaded" class="skeleton h-40 rounded-card" />
          <p v-else-if="opsFailed && !transactions.length" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>
          <UiEmpty v-else-if="!transactions.length" :icon="ReceiptText" :text="$t('details.accountEmpty')" tone="money" />
          <div v-for="group in days" :key="group.day" class="flex flex-col">
            <div class="flex items-baseline justify-between pt-2 pb-1">
              <span class="text-caption font-semibold text-ink-2">{{ group.heading }}</span>
              <MoneyAmount v-if="group.net" :amount="group.net" :currency="account.currency" sign="always" class="text-caption text-ink-3" />
            </div>
            <div class="group">
              <TransactionRow v-for="item in group.items" :key="item.id" :transaction="item" @click="quick.show(item)" />
            </div>
          </div>
          <UiButton v-if="nextCursor" variant="secondary" block :loading="loadingMore" class="mt-1" @click="loadMore">{{ $t('money.more') }}</UiButton>
        </section>
      </div>
    </div>
  </div>
</template>
