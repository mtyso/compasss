<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { ArrowDown, Trash2, X } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import UiSpinner from '@/components/ui/UiSpinner.vue'
import UiSwitch from '@/components/ui/UiSwitch.vue'
import { showToast } from '@/components/ui/toast'
import { haptics } from '@/lib/haptics'
import { softHyphenate } from '@/lib/hyphenate'
import { CATEGORY_COLORS } from '@/lib/palette'
import { ApiError } from '@/lib/api'
import { isoDate, isoTime, shiftDate } from '@/lib/dates'
import { groupDigits, groupInput } from '@/lib/digits'
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

const typeOptions = computed(() => (['expense', 'income', 'transfer'] as const).map((value) => ({ value, label: t(`quickAdd.types.${value}`) })))

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
    if (!quick.editing && sheetSettled.value) focusAmount()
  },
)

// The keyboard opens once the sheet has arrived, so the two don't fight over the same frames.
const sheetSettled = ref(false)
function onClosed(): void {
  sheetSettled.value = false
}
function onOpened(): void {
  sheetSettled.value = true
  if (!quick.editing && !loading.value) focusAmount()
}
async function focusAmount(): Promise<void> {
  await nextTick()
  amountInput.value?.focus({ preventScroll: true })
}

function fill(transaction: Transaction | null): void {
  error.value = null
  if (transaction) {
    type.value = transaction.type
    amountText.value = groupDigits(amountToInput(transaction.amount, transaction.currency))
    accountId.value = transaction.account_id
    toAccountId.value = transaction.to_account_id
    const target = transaction.to_account_id ? money.accountById.get(transaction.to_account_id) : null
    toAmountText.value = transaction.to_amount && target ? groupDigits(amountToInput(transaction.to_amount, target.currency)) : ''
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
  haptics.selection()
  categoryId.value = category.id
  openParentId.value = money.childCategories(category.id).length ? category.id : null
  // The subcategories appear under the grid; bring them into view so they're noticed.
  if (openParentId.value) void nextTick(() => subcategories.value?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }))
}

const subcategories = ref<HTMLElement | null>(null)

const typedPlain = computed(() => /^[\d\s\u00a0\u202f]*$/.test(amountText.value))

function ringOf(category: Category): string {
  return CATEGORY_COLORS[category.color ?? 'gray'] ?? CATEGORY_COLORS.gray!
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
    haptics.notify('success')
    showToast({ message: quick.editing ? t('ui.updated') : t('ui.saved'), detail: savedDetail(), tone: 'success' })
    quick.close()
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? Object.values(e.errors)[0]?.[0] ?? e.message : t('quickAdd.failed')
  } finally {
    saving.value = false
  }
}

/** «Кафе и рестораны, −85 000 сум» for the toast after saving. */
function savedDetail(): string {
  const sum = formatMoney(amount.value ?? 0, currency.value, settings.locale)
  if (type.value === 'transfer') return `${account.value?.name ?? ''} → ${toAccount.value?.name ?? ''}, ${sum}`
  const category = categoryId.value ? money.categoryById.get(categoryId.value) : undefined
  return `${category ? name(category) : ''}, ${type.value === 'expense' ? '−' : '+'}${sum}`
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

function pickChild(category: Category): void {
  haptics.selection()
  categoryId.value = category.id
}

function pickAccount(id: number): void {
  haptics.selection()
  accountId.value = id
  if (toAccountId.value === id) toAccountId.value = null
}

function addAccount(): void {
  quick.close()
  router.push({ name: 'account-new' })
}
</script>

<template>
  <UiSheet v-model:open="quick.open" :label="$t('quickAdd.title')" @opened="onOpened" @closed="onClosed">
    <template #header>
      <header class="flex items-center gap-3 pr-3 pb-3 pl-5">
        <UiSegmented
          class="flex-1"
          :model-value="type"
          :options="typeOptions"
          :label="$t('quickAdd.title')"
          @update:model-value="setType"
        />
        <button
          type="button"
          class="press grid size-9 shrink-0 place-items-center rounded-full bg-surface-2 text-ink-2"
          :aria-label="$t('quickAdd.close')"
          @click="quick.close()"
        >
          <X class="size-[18px]" :stroke-width="2.4" />
        </button>
      </header>
    </template>

    <div v-if="loading" class="grid place-items-center py-16 text-ink-3"><UiSpinner /></div>

    <div v-else-if="!money.activeAccounts.length" class="flex flex-col items-start gap-4 pt-2">
      <p class="text-ink-2">{{ $t('quickAdd.noAccounts') }}</p>
      <UiButton @click="addAccount">{{ $t('quickAdd.addAccount') }}</UiButton>
    </div>

    <div v-else class="flex flex-col gap-5 pt-1">
      <!-- Amount: the first thing the eye and the keyboard land on. It stays pinned while the rest
           scrolls, so picking a category never hides what you typed. The hint under the line sits in
           the padding below it, so nothing jumps while typing «32к». -->
      <div class="amount-pin sticky top-0 z-10 -mx-5 -mt-1 -mb-3 bg-raised px-5 pt-1 pb-7">
        <label class="relative flex flex-col">
          <span class="sr-only">{{ $t('quickAdd.amount') }}</span>
          <span class="amount-line flex items-baseline gap-2 pb-2" :class="{ 'is-on': amount }">
            <input
              ref="amountInput"
              :value="amountText"
              inputmode="decimal"
              enterkeyhint="done"
              autocomplete="off"
              placeholder="0"
              class="num min-w-0 flex-1 bg-transparent font-display text-[2.75rem] leading-none font-bold tracking-[-0.03em] outline-none placeholder:text-ink/20"
              :class="type === 'income' ? 'text-money' : 'text-ink'"
              @input="groupInput($event, (value) => (amountText = value))"
              @keydown.enter="save"
            />
            <span class="font-display text-xl font-semibold text-ink-3">{{ currency === 'USD' ? '$' : $t('quickAdd.som') }}</span>
          </span>
          <span class="num absolute top-full left-0 mt-1 text-caption text-ink-3">
            <template v-if="amount && !typedPlain">= {{ formatMoney(amount, currency, settings.locale) }}</template>
            <template v-if="approxUzs"> ≈ {{ formatMoney(approxUzs, 'UZS', settings.locale) }}</template>
          </span>
        </label>
      </div>

      <!-- Accounts -->
      <section class="flex flex-col gap-2.5">
        <h3 class="section-title">{{ type === 'transfer' ? $t('quickAdd.from') : $t('quickAdd.account') }}</h3>
        <div class="no-scrollbar -mx-5 flex gap-2 overflow-x-auto px-5 py-0.5">
          <button
            v-for="item in money.activeAccounts"
            :key="item.id"
            type="button"
            class="chip"
            :class="{ 'chip-on': accountId === item.id }"
            :aria-pressed="accountId === item.id"
            @click="pickAccount(item.id)"
          >
            <span class="font-medium">{{ item.name }}</span>
            <span class="num text-caption opacity-60">{{ formatMoney(item.balance, item.currency, settings.locale) }}</span>
          </button>
        </div>
      </section>

      <template v-if="type === 'transfer'">
        <section class="flex flex-col gap-2.5">
          <h3 class="section-title flex items-center gap-1"><ArrowDown class="size-3.5" :stroke-width="2.4" />{{ $t('quickAdd.to') }}</h3>
          <div class="no-scrollbar -mx-5 flex gap-2 overflow-x-auto px-5 py-0.5">
            <button
              v-for="item in targetAccounts"
              :key="item.id"
              type="button"
              class="chip"
              :class="{ 'chip-on': toAccountId === item.id }"
              :aria-pressed="toAccountId === item.id"
              @click="toAccountId = item.id"
            >
              <span class="font-medium">{{ item.name }}</span>
              <span class="num text-caption opacity-60">{{ formatMoney(item.balance, item.currency, settings.locale) }}</span>
            </button>
          </div>
        </section>
        <Transition name="fade">
          <label v-if="needsToAmount && toAccount" class="flex flex-col gap-1.5">
            <span class="section-title">{{ $t('quickAdd.received', { account: toAccount.name }) }}</span>
            <span class="field flex items-baseline gap-2">
              <input :value="toAmountText" inputmode="decimal" autocomplete="off" placeholder="0" class="num min-w-0 flex-1 bg-transparent text-xl font-semibold outline-none" @input="groupInput($event, (value) => (toAmountText = value))" />
              <span class="text-sm text-ink-3">{{ toAccount.currency === 'USD' ? '$' : $t('quickAdd.som') }}</span>
            </span>
          </label>
        </Transition>
      </template>

      <!-- Categories -->
      <section v-else class="flex flex-col gap-2.5">
        <h3 class="section-title">{{ $t('quickAdd.category') }}</h3>
        <div class="-mx-1 grid grid-cols-4 gap-x-1 gap-y-2">
          <button
            v-for="item in parents"
            :key="item.id"
            type="button"
            class="cat press flex flex-col items-center gap-1.5 rounded-2xl px-0.5 pt-1.5 pb-2 text-center"
            :class="{ 'is-on': openParentId === item.id || categoryId === item.id }"
            :aria-pressed="categoryId === item.id || openParentId === item.id"
            @click="pickParent(item)"
          >
            <span class="cat-icon rounded-2xl" :style="{ '--ring': ringOf(item) }">
              <CategoryIcon :icon="item.icon" :color="item.color" size="lg" />
            </span>
            <span class="line-clamp-2 w-full text-[0.71875rem] leading-tight hyphens-manual">{{ softHyphenate(name(item)) }}</span>
          </button>
        </div>
        <Transition name="fade">
          <div v-if="children.length" ref="subcategories" :key="openParentId ?? 0" class="flex scroll-mb-4 flex-wrap gap-2 pt-1">
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
              @click="pickChild(item)"
            >
              {{ name(item) }}
            </button>
          </div>
        </Transition>
      </section>

      <!-- Date -->
      <section class="flex flex-col gap-2.5">
        <h3 class="section-title">{{ $t('quickAdd.date') }}</h3>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="chip !py-1.5" :class="{ 'chip-on': day === isoDate() }" @click="day = isoDate()">{{ $t('quickAdd.today') }}</button>
          <button type="button" class="chip !py-1.5" :class="{ 'chip-on': day === shiftDate(isoDate(), -1) }" @click="day = shiftDate(isoDate(), -1)">
            {{ $t('quickAdd.yesterday') }}
          </button>
          <input v-model="day" type="date" :max="isoDate()" class="chip !py-1.5" :class="{ 'chip-on': day < shiftDate(isoDate(), -1) }" />
        </div>
      </section>

      <input v-model="note" maxlength="255" :placeholder="$t('quickAdd.notePlaceholder')" class="field text-body" />

      <div v-if="type === 'expense'" class="group -mt-2">
        <UiSwitch v-model="oneOff" :title="$t('quickAdd.oneOff')" :hint="$t('quickAdd.oneOffHint')" />
      </div>
    </div>

    <template v-if="!loading && money.activeAccounts.length" #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <div class="flex gap-2">
        <UiButton v-if="quick.editing" variant="danger-soft" class="!w-12 !px-0" :aria-label="$t('quickAdd.delete')" :disabled="saving" @click="remove">
          <Trash2 class="size-5" />
        </UiButton>
        <UiButton class="flex-1" :disabled="!canSave" :loading="saving" @click="save">
          {{ quick.editing ? $t('quickAdd.saveChanges') : $t('quickAdd.save') }}
        </UiButton>
      </div>
    </template>
  </UiSheet>
</template>

<style scoped>
/* The underline under the amount draws in from the left once there is a valid amount. */
.amount-line {
  position: relative;
  box-shadow: inset 0 -2px 0 var(--c-line);
}

.amount-line::after {
  content: '';
  position: absolute;
  inset: auto 0 0;
  height: 2px;
  border-radius: 2px;
  background: var(--c-ink);
  transform: scaleX(0);
  transform-origin: left;
  transition:
    transform 420ms var(--ease-enter),
    opacity var(--dur-fast) var(--ease-standard);
}

/* Content scrolling under the pinned amount fades out instead of being cut at a hard edge. */
.amount-pin::after {
  content: '';
  position: absolute;
  inset: 100% 0 auto;
  height: 0.5rem;
  background: linear-gradient(var(--c-raised), transparent);
  pointer-events: none;
}

/* Focused: the line draws in faintly; once there's an amount it turns solid. */
.amount-line:focus-within::after {
  transform: none;
  opacity: 0.3;
}

.amount-line.is-on::after {
  transform: none;
  opacity: 1;
}

/* Chosen category: its tile sits on a soft plate, the icon gets a ring and pops. */
.cat.is-on {
  background: var(--c-surface-2);
}

.cat-icon {
  display: block;
  transition:
    transform var(--dur-pop) var(--ease-pop),
    box-shadow var(--dur-fast) var(--ease-standard);
}

.cat.is-on .cat-icon {
  transform: scale(1.08);
  box-shadow:
    0 0 0 2px var(--c-surface-2),
    0 0 0 4px var(--ring, var(--c-ink));
}

.cat.is-on span:last-child {
  font-weight: 600;
}

@media (prefers-reduced-motion: reduce) {
  .cat.is-on .cat-icon {
    transform: none;
  }
}
</style>
