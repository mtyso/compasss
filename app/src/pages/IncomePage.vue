<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Loader2, Plus, Trash2 } from 'lucide-vue-next'
import AmountField from '@/components/AmountField.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import PageHeader from '@/components/PageHeader.vue'
import { api, ApiError } from '@/lib/api'
import { formatDayMonth, formatMoney, type Currency } from '@/lib/format'
import { CURRENCIES } from '@/lib/money'
import { useAuth, type User } from '@/stores/auth'
import { useMoney } from '@/stores/money'
import { usePlan, type IncomeSchedule } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

const plan = usePlan()
const money = useMoney()
const auth = useAuth()
const settings = useSettings()
const { t } = useI18n()

const sheetOpen = ref(false)
const editing = ref<IncomeSchedule | null>(null)
const name = ref('')
const day = ref(10)
const amount = ref<number | null>(null)
const currency = ref<Currency>('UZS')
const accountId = ref<number | null>(null)
const saving = ref(false)
const error = ref<string | null>(null)

const minBalance = ref<number | null>(Number(auth.user?.settings.min_balance_uzs ?? 0) || null)
const minSaved = ref(false)

onMounted(() => Promise.all([plan.loadIncomes(), money.ensureLoaded()]))

function open(schedule: IncomeSchedule | null): void {
  editing.value = schedule
  name.value = schedule?.name ?? t('income.defaultName')
  day.value = schedule?.day_of_month ?? 10
  amount.value = schedule?.expected_amount ?? null
  currency.value = schedule?.currency ?? 'UZS'
  accountId.value = schedule?.account_id ?? null
  error.value = null
  sheetOpen.value = true
}

const canSave = computed(() => name.value.trim() !== '' && day.value >= 1 && day.value <= 31 && amount.value !== null)

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    await plan.saveIncome(
      { name: name.value.trim(), day_of_month: day.value, expected_amount: amount.value ?? 0, currency: currency.value, account_id: accountId.value },
      editing.value?.id,
    )
    sheetOpen.value = false
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('quickAdd.failed')
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  if (!editing.value) return
  await plan.deleteIncome(editing.value.id)
  sheetOpen.value = false
}

async function saveMinBalance(): Promise<void> {
  const user = await api<User>('/me', { method: 'PATCH', body: JSON.stringify({ settings: { min_balance_uzs: minBalance.value ?? 0 } }) })
  auth.setUser(user)
  minSaved.value = true
  window.setTimeout(() => (minSaved.value = false), 2000)
}
</script>

<template>
  <div class="flex flex-col gap-5">
    <PageHeader :title="$t('income.title')" />
    <p class="px-1 text-sm text-ink-2">{{ $t('income.intro') }}</p>

    <div class="flex flex-col divide-y divide-line overflow-hidden rounded-3xl bg-surface shadow-sm">
      <button v-for="schedule in plan.incomes" :key="schedule.id" type="button" class="flex items-center gap-3 px-4 py-3 text-left active:bg-bg" @click="open(schedule)">
        <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-money-soft font-display text-sm font-bold text-money">{{ schedule.day_of_month }}</span>
        <span class="flex min-w-0 flex-1 flex-col">
          <span class="truncate font-medium">{{ schedule.name }}</span>
          <span class="text-xs text-ink-3">{{ $t('income.next', { date: formatDayMonth(schedule.next_date, settings.locale) }) }}</span>
        </span>
        <span class="num font-semibold">{{ formatMoney(schedule.expected_amount, schedule.currency, settings.locale) }}</span>
      </button>
      <button type="button" class="flex items-center gap-3 px-4 py-3 text-ink-2 active:bg-bg" @click="open(null)">
        <Plus class="size-5" />
        {{ $t('income.add') }}
      </button>
    </div>

    <section class="flex flex-col gap-2 rounded-3xl bg-surface p-4 shadow-sm">
      <AmountField v-model="minBalance" currency="UZS" :label="$t('income.minBalance')" allow-zero />
      <p class="px-1 text-xs text-ink-3">{{ $t('income.minBalanceHint') }}</p>
      <button type="button" class="self-start rounded-xl bg-ink px-4 py-2 text-sm font-semibold text-surface" @click="saveMinBalance">
        {{ minSaved ? $t('income.saved') : $t('accounts.save') }}
      </button>
    </section>

    <BottomSheet v-model:open="sheetOpen" :title="editing ? editing.name : $t('income.new')">
      <label class="flex flex-col gap-1.5">
        <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('accounts.name') }}</span>
        <input v-model="name" maxlength="60" class="field" />
      </label>
      <label class="flex flex-col gap-1.5">
        <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('income.day') }}</span>
        <input v-model.number="day" type="number" min="1" max="31" inputmode="numeric" class="field num w-28" />
        <span class="px-1 text-xs text-ink-3">{{ $t('income.dayHint') }}</span>
      </label>
      <section class="flex flex-col gap-1.5">
        <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('accounts.currency') }}</span>
        <div class="flex gap-2">
          <button v-for="item in CURRENCIES" :key="item" type="button" class="chip" :class="{ 'chip-on': currency === item }" @click="currency = item">
            {{ item === 'UZS' ? $t('accounts.som') : $t('accounts.dollar') }}
          </button>
        </div>
      </section>
      <AmountField v-model="amount" :currency="currency" :label="$t('income.amount')" />
      <section class="flex flex-col gap-1.5">
        <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('income.account') }}</span>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="chip" :class="{ 'chip-on': accountId === null }" @click="accountId = null">{{ $t('income.anyAccount') }}</button>
          <button v-for="item in money.activeAccounts" :key="item.id" type="button" class="chip" :class="{ 'chip-on': accountId === item.id }" @click="accountId = item.id">
            {{ item.name }}
          </button>
        </div>
      </section>
      <template #footer>
        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
        <div class="flex gap-2">
          <button v-if="editing" type="button" class="grid size-12 place-items-center rounded-2xl bg-danger-soft text-danger" :aria-label="$t('quickAdd.delete')" @click="remove">
            <Trash2 class="size-5" />
          </button>
          <button type="button" class="flex flex-1 items-center justify-center gap-2 rounded-2xl bg-ink py-3 font-semibold text-surface disabled:opacity-40" :disabled="!canSave || saving" @click="save">
            <Loader2 v-if="saving" class="size-5 animate-spin" />
            {{ $t('accounts.save') }}
          </button>
        </div>
      </template>
    </BottomSheet>
  </div>
</template>
