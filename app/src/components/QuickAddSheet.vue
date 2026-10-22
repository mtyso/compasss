<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { ArrowRight, Loader2, Trash2, X } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import { ApiError } from '@/lib/api'
import { isoDate, isoTime, shiftDate } from '@/lib/dates'
import { formatMoney } from '@/lib/format'
import { amountToInput, parseAmount, toUzs } from '@/lib/money'
import { readJson, writeJson } from '@/lib/storage'
import { useMoney, useQuickAdd, type Category, type Transaction, type TransactionType } from '@/stores/money'
import { useSettings } from '@/stores/settings'

const LAST_ACCOUNT_KEY = 'kompas.lastAccount'

const quick = useQuickAdd()
const money = useMoney()
const settings = useSettings()
const router = useRouter()
const { t } = useI18n()

const type = ref<TransactionType>('expense')
const amountText = ref('')
const toAmountText = ref('')
const accountId = ref<number | null>(null)
const toAccountId = ref<number | null>(null)
const categoryId = ref<number | null>(null)
const openParentId = ref<number | null>(null)
const day = ref(isoDate())
const time = ref(isoTime())
const note = ref('')
const oneOff = ref(false)
const saving = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)
const amountInput = ref<HTMLInputElement | null>(null)

const types: TransactionType[] = ['expense', 'income', 'transfer']

watch(
  () => quick.open,
  async (open) => {
    if (!open) return
    loading.value = true
    try {
      await money.ensureLoaded()
    } catch {
      error.value = t('quickAdd.failed')
    }
    loading.value = false
    fill(quick.editing)
    if (!quick.editing) {
      await nextTick()
      amountInput.value?.focus()
    }
  },
)

function fill(transaction: Transaction | null): void {
  error.value = null
  if (transaction) {
    type.value = transaction.type
    amountText.value = amountToInput(transaction.amount, transaction.currency)
    accountId.value = transaction.account_id
    toAccountId.value = transaction.to_account_id
    const target = transaction.to_account_id ? money.accountById.get(transaction.to_account_id) : null
    toAmountText.value = transaction.to_amount && target ? amountToInput(transaction.to_amount, target.currency) : ''
    categoryId.value = transaction.category_id
    const category = transaction.category_id ? money.categoryById.get(transaction.category_id) : null
    openParentId.value = category ? money.rootOf(category).id : null
    day.value = isoDate(new Date(transaction.occurred_at))
    time.value = isoTime(new Date(transaction.occurred_at))
    note.value = transaction.note ?? ''
    oneOff.value = transaction.is_one_off
    return
  }
  const remembered = readJson<number>(LAST_ACCOUNT_KEY)
  type.value = 'expense'
  amountText.value = ''
  toAmountText.value = ''
  accountId.value = money.activeAccounts.find((a) => a.id === remembered)?.id ?? money.activeAccounts[0]?.id ?? null
  toAccountId.value = null
  categoryId.value = null
  openParentId.value = null
  day.value = isoDate()
  time.value = isoTime()
  note.value = ''
  oneOff.value = false
}

const account = computed(() => (accountId.value ? money.accountById.get(accountId.value) : undefined))
const toAccount = computed(() => (toAccountId.value ? money.accountById.get(toAccountId.value) : undefined))
const currency = computed(() => account.value?.currency ?? 'UZS')
const amount = computed(() => parseAmount(amountText.value, currency.value))
const needsToAmount = computed(
  () => type.value === 'transfer' && !!account.value && !!toAccount.value && account.value.currency !== toAccount.value.currency,
)
const toAmount = computed(() => (toAccount.value ? parseAmount(toAmountText.value, toAccount.value.currency) : null))
const approxUzs = computed(() => {
  const rate = money.rates[currency.value]
  return currency.value !== 'UZS' && amount.value && rate ? toUzs(amount.value, currency.value, rate) : null
})

const parents = computed(() => (type.value === 'transfer' ? [] : money.topCategories(type.value)))
const children = computed(() => (openParentId.value ? money.childCategories(openParentId.value) : []))
const targetAccounts = computed(() => money.activeAccounts.filter((a) => a.id !== accountId.value))

const canSave = computed(() => {
  if (!amount.value || !accountId.value) return false
  if (type.value === 'transfer') {
    return !!toAccountId.value && toAccountId.value !== accountId.value && (!needsToAmount.value || !!toAmount.value)
  }
  return !!categoryId.value
})

function setType(next: TransactionType): void {
  if (next === type.value) return
  type.value = next
  categoryId.value = null
  openParentId.value = null
}

function name(category: Category): string {
  return category.names[settings.locale]
}

function pickParent(category: Category): void {
  categoryId.value = category.id
  openParentId.value = money.childCategories(category.id).length ? category.id : null
}

async function save(): Promise<void> {
  if (!canSave.value || !accountId.value || !amount.value) return
  saving.value = true
  error.value = null
  const isNewToday = !quick.editing && day.value === isoDate()
  try {
    await money.saveTransaction(
      {
        type: type.value,
        account_id: accountId.value,
        amount: amount.value,
        ...(type.value === 'transfer'
          ? { to_account_id: toAccountId.value!, ...(needsToAmount.value ? { to_amount: toAmount.value! } : {}) }
          : { category_id: categoryId.value! }),
        note: note.value.trim() || undefined,
        occurred_at: `${day.value} ${isNewToday ? isoTime() : time.value}`,
        is_one_off: type.value === 'expense' && oneOff.value,
      },
      quick.editing?.id,
    )
    writeJson(LAST_ACCOUNT_KEY, accountId.value)
    quick.close()
  } catch (e) {
    error.value = e instanceof ApiError && e.status === 422 ? Object.values(e.errors)[0]?.[0] ?? e.message : t('quickAdd.failed')
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  const editing = quick.editing
  if (!editing) return
  saving.value = true
  try {
    await money.deleteTransaction(editing.id)
    quick.deletedId = editing.id
    quick.close()
  } catch {
    error.value = t('quickAdd.failed')
  } finally {
    saving.value = false
  }
}

function addAccount(): void {
  quick.close()
  router.push({ name: 'account-new' })
}
</script>

<template>
  <Transition
    enter-from-class="opacity-0"
    leave-to-class="opacity-0"
    enter-active-class="transition-opacity duration-200 motion-reduce:transition-none"
    leave-active-class="transition-opacity duration-150 motion-reduce:transition-none"
  >
    <div v-if="quick.open" class="fixed inset-0 z-20 flex items-end bg-black/40" @click.self="quick.close()">
      <div
        class="sheet mx-auto flex max-h-[92dvh] w-full max-w-xl flex-col rounded-t-3xl bg-surface"
        role="dialog"
        aria-modal="true"
        :aria-label="$t('quickAdd.title')"
        @keydown.esc="quick.close()"
      >
        <header class="flex items-center gap-3 px-5 pt-4 pb-3">
          <div class="grid flex-1 grid-cols-3 gap-1 rounded-2xl bg-line p-1">
            <button
              v-for="item in types"
              :key="item"
              type="button"
              class="rounded-xl py-1.5 text-sm font-medium transition-colors"
              :class="type === item ? 'bg-surface text-ink shadow-sm' : 'text-ink-2'"
              @click="setType(item)"
            >
              {{ $t(`quickAdd.types.${item}`) }}
            </button>
          </div>
          <button type="button" class="grid size-9 place-items-center rounded-full text-ink-2 active:bg-line" :aria-label="$t('quickAdd.close')" @click="quick.close()">
            <X class="size-5" />
          </button>
        </header>

        <div v-if="loading" class="grid place-items-center py-16"><Loader2 class="size-6 animate-spin text-ink-3" /></div>

        <div v-else-if="!money.activeAccounts.length" class="flex flex-col items-start gap-3 px-5 pt-2 pb-[max(1.25rem,env(safe-area-inset-bottom))]">
          <p class="text-sm text-ink-2">{{ $t('quickAdd.noAccounts') }}</p>
          <button type="button" class="rounded-2xl bg-ink px-4 py-2.5 text-sm font-semibold text-surface" @click="addAccount">
            {{ $t('quickAdd.addAccount') }}
          </button>
        </div>

        <template v-else>
          <div class="flex flex-col gap-5 overflow-y-auto px-5 pb-4">
            <!-- Amount -->
            <label class="flex flex-col gap-1">
              <span class="sr-only">{{ $t('quickAdd.amount') }}</span>
              <span class="flex items-baseline gap-2 border-b-2 pb-1 transition-colors" :class="amount ? 'border-ink' : 'border-line'">
                <input
                  ref="amountInput"
                  v-model="amountText"
                  inputmode="decimal"
                  autocomplete="off"
                  placeholder="0"
                  class="num min-w-0 flex-1 bg-transparent font-display text-4xl font-bold outline-none placeholder:text-ink-3"
                  @keydown.enter="save"
                />
                <span class="text-lg font-semibold text-ink-3">{{ currency === 'USD' ? '$' : $t('quickAdd.som') }}</span>
              </span>
              <span class="h-4 text-xs text-ink-3">
                <template v-if="amount && amountText !== amountToInput(amount, currency)">= {{ formatMoney(amount, currency, settings.locale) }}</template>
                <template v-if="approxUzs"> ≈ {{ formatMoney(approxUzs, 'UZS', settings.locale) }}</template>
              </span>
            </label>

            <!-- Accounts -->
            <section class="flex flex-col gap-2">
              <h3 class="text-xs font-semibold text-ink-3">{{ type === 'transfer' ? $t('quickAdd.from') : $t('quickAdd.account') }}</h3>
              <div class="-mx-5 flex gap-2 overflow-x-auto px-5 pb-1">
                <button
                  v-for="item in money.activeAccounts"
                  :key="item.id"
                  type="button"
                  class="chip"
                  :class="{ 'chip-on': accountId === item.id }"
                  @click="accountId = item.id; if (toAccountId === item.id) toAccountId = null"
                >
                  <span class="font-medium">{{ item.name }}</span>
                  <span class="num text-xs opacity-70">{{ formatMoney(item.balance, item.currency, settings.locale) }}</span>
                </button>
              </div>
            </section>

            <template v-if="type === 'transfer'">
              <section class="flex flex-col gap-2">
                <h3 class="flex items-center gap-1 text-xs font-semibold text-ink-3"><ArrowRight class="size-3.5" />{{ $t('quickAdd.to') }}</h3>
                <div class="-mx-5 flex gap-2 overflow-x-auto px-5 pb-1">
                  <button
                    v-for="item in targetAccounts"
                    :key="item.id"
                    type="button"
                    class="chip"
                    :class="{ 'chip-on': toAccountId === item.id }"
                    @click="toAccountId = item.id"
                  >
                    <span class="font-medium">{{ item.name }}</span>
                    <span class="num text-xs opacity-70">{{ formatMoney(item.balance, item.currency, settings.locale) }}</span>
                  </button>
                </div>
              </section>
              <label v-if="needsToAmount && toAccount" class="flex flex-col gap-1">
                <span class="text-xs font-semibold text-ink-3">{{ $t('quickAdd.received', { account: toAccount.name }) }}</span>
                <span class="flex items-baseline gap-2 border-b border-line pb-1">
                  <input v-model="toAmountText" inputmode="decimal" placeholder="0" class="num min-w-0 flex-1 bg-transparent text-2xl font-semibold outline-none" />
                  <span class="text-sm text-ink-3">{{ toAccount.currency === 'USD' ? '$' : $t('quickAdd.som') }}</span>
                </span>
              </label>
            </template>

            <!-- Categories -->
            <section v-else class="flex flex-col gap-2">
              <h3 class="text-xs font-semibold text-ink-3">{{ $t('quickAdd.category') }}</h3>
              <div class="grid grid-cols-4 gap-x-1 gap-y-3">
                <button
                  v-for="item in parents"
                  :key="item.id"
                  type="button"
                  class="flex flex-col items-center gap-1.5 rounded-2xl p-1 text-center"
                  :class="openParentId === item.id || categoryId === item.id ? 'bg-bg' : ''"
                  :aria-pressed="categoryId === item.id || openParentId === item.id"
                  @click="pickParent(item)"
                >
                  <span class="rounded-2xl ring-offset-2 ring-offset-surface transition" :class="{ 'ring-2 ring-ink': categoryId === item.id || openParentId === item.id }">
                    <CategoryIcon :icon="item.icon" :color="item.color" />
                  </span>
                  <span class="line-clamp-2 w-full text-[11px] leading-tight hyphens-auto [overflow-wrap:anywhere]">{{ name(item) }}</span>
                </button>
              </div>
              <div v-if="children.length" class="flex flex-wrap gap-2 pt-1">
                <button
                  type="button"
                  class="chip !py-1.5"
                  :class="{ 'chip-on': categoryId === openParentId }"
                  @click="categoryId = openParentId"
                >
                  {{ $t('quickAdd.wholeCategory') }}
                </button>
                <button
                  v-for="item in children"
                  :key="item.id"
                  type="button"
                  class="chip !py-1.5"
                  :class="{ 'chip-on': categoryId === item.id }"
                  @click="categoryId = item.id"
                >
                  {{ name(item) }}
                </button>
              </div>
            </section>

            <!-- Date -->
            <section class="flex flex-col gap-2">
              <h3 class="text-xs font-semibold text-ink-3">{{ $t('quickAdd.date') }}</h3>
              <div class="flex flex-wrap gap-2">
                <button type="button" class="chip !py-1.5" :class="{ 'chip-on': day === isoDate() }" @click="day = isoDate()">{{ $t('quickAdd.today') }}</button>
                <button type="button" class="chip !py-1.5" :class="{ 'chip-on': day === shiftDate(isoDate(), -1) }" @click="day = shiftDate(isoDate(), -1)">
                  {{ $t('quickAdd.yesterday') }}
                </button>
                <input v-model="day" type="date" :max="isoDate()" class="chip !py-1.5" :class="{ 'chip-on': day < shiftDate(isoDate(), -1) }" />
              </div>
            </section>

            <input
              v-model="note"
              maxlength="255"
              :placeholder="$t('quickAdd.notePlaceholder')"
              class="rounded-2xl border border-line bg-transparent px-4 py-3 text-sm outline-none focus:border-ink-3"
            />

            <label v-if="type === 'expense'" class="flex items-start gap-3">
              <input v-model="oneOff" type="checkbox" class="mt-0.5 size-5 accent-[var(--c-ink)]" />
              <span class="flex flex-col">
                <span class="text-sm font-medium">{{ $t('quickAdd.oneOff') }}</span>
                <span class="text-xs text-ink-3">{{ $t('quickAdd.oneOffHint') }}</span>
              </span>
            </label>
          </div>

          <footer class="flex flex-col gap-2 border-t border-line px-5 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
            <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
            <div class="flex gap-2">
              <button
                v-if="quick.editing"
                type="button"
                class="grid size-12 place-items-center rounded-2xl bg-danger-soft text-danger disabled:opacity-50"
                :aria-label="$t('quickAdd.delete')"
                :disabled="saving"
                @click="remove"
              >
                <Trash2 class="size-5" />
              </button>
              <button
                type="button"
                class="flex flex-1 items-center justify-center gap-2 rounded-2xl bg-ink py-3 font-semibold text-surface transition-opacity disabled:opacity-40"
                :disabled="!canSave || saving"
                @click="save"
              >
                <Loader2 v-if="saving" class="size-5 animate-spin" />
                {{ quick.editing ? $t('quickAdd.saveChanges') : $t('quickAdd.save') }}
              </button>
            </div>
          </footer>
        </template>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
@reference "../style.css";

.sheet {
  animation: sheet-up 220ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

@keyframes sheet-up {
  from { transform: translateY(24px); }
  to { transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
  .sheet { animation: none; }
}
</style>
