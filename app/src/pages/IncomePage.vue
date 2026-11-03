<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Pencil, Plus, Trash2 } from 'lucide-vue-next'
import AmountField from '@/components/AmountField.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import PageHeader from '@/components/PageHeader.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiRow from '@/components/ui/UiRow.vue'
import UiFact from '@/components/ui/UiFact.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { showToast } from '@/components/ui/toast'
import { haptics } from '@/lib/haptics'
import { api, ApiError } from '@/lib/api'
import { isoDate } from '@/lib/dates'
import { formatDayMonth, type Currency } from '@/lib/format'
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

const minBalance = ref<number | null>(Number(auth.user?.settings?.min_balance_uzs ?? 0) || null)
const minSaving = ref(false)
const loaded = ref(false)

onMounted(async () => {
  await Promise.all([plan.loadIncomes(), money.ensureLoaded()]).catch(() => {})
  loaded.value = true
})

const currencyOptions = computed(() => CURRENCIES.map((value) => ({ value, label: value === 'UZS' ? t('accounts.som') : t('accounts.dollar') })))

// A tap on a day of income shows it first (D42); its «Изменить» opens the form.
const viewOpen = ref(false)
const viewing = ref<IncomeSchedule | null>(null)
function view(schedule: IncomeSchedule): void {
  viewing.value = schedule
  viewOpen.value = true
}
function editViewed(): void {
  if (!viewing.value) return
  viewOpen.value = false
  open(viewing.value)
}

/** «через 7 дней», or «сегодня», for the next day the money comes. */
function inDays(day: string): string {
  const days = Math.round((Date.parse(`${day}T12:00:00+05:00`) - Date.parse(`${isoDate()}T12:00:00+05:00`)) / 86_400_000)
  return days <= 0 ? t('details.today') : t('today.daysLeft', { n: days }, days)
}

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
    haptics.notify('success')
  } catch (e) {
    haptics.notify('error')
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
  minSaving.value = true
  try {
    const user = await api<User>('/me', { method: 'PATCH', body: JSON.stringify({ settings: { min_balance_uzs: minBalance.value ?? 0 } }) })
    auth.setUser(user)
    haptics.notify('success')
    showToast({ message: t('income.saved'), tone: 'success' })
  } catch {
    haptics.notify('error')
    showToast({ message: t('quickAdd.failed'), tone: 'danger' })
  } finally {
    minSaving.value = false
  }
}
</script>

<template>
  <div class="page">
    <PageHeader :title="$t('income.title')" />

    <section class="flex flex-col gap-2.5">
      <p class="text-caption text-ink-3">{{ $t('income.intro') }}</p>
      <div v-if="!loaded" class="skeleton h-28 rounded-card" />
      <div v-else class="group">
        <UiRow v-for="schedule in plan.incomes" :key="schedule.id" :title="schedule.name" @click="view(schedule)">
          <template #leading>
            <span class="num grid size-10 place-items-center rounded-full bg-surface-2 text-[0.9375rem] font-semibold text-ink">{{ schedule.day_of_month }}</span>
          </template>
          <template #subtitle>{{ $t('income.next', { date: formatDayMonth(schedule.next_date, settings.locale) }) }}</template>
          <template #trailing>
            <MoneyAmount :amount="schedule.expected_amount" :currency="schedule.currency" class="font-semibold" />
          </template>
        </UiRow>
        <UiRow :title="$t('income.add')" tone="muted" :chevron="false" @click="open(null)">
          <template #leading>
            <span class="grid size-10 place-items-center text-ink-3"><Plus class="size-5" :stroke-width="2.2" /></span>
          </template>
        </UiRow>
      </div>
    </section>

    <section class="flex flex-col gap-3">
      <AmountField v-model="minBalance" currency="UZS" :label="$t('income.minBalance')" allow-zero />
      <p class="text-caption text-ink-3">{{ $t('income.minBalanceHint') }}</p>
      <UiButton variant="secondary" size="sm" class="self-start" :loading="minSaving" @click="saveMinBalance">{{ $t('accounts.save') }}</UiButton>
    </section>

    <UiSheet v-model:open="viewOpen" :title="viewing?.name ?? ''">
      <div v-if="viewing" class="flex flex-col gap-4 pt-1">
        <section class="flex items-center gap-4">
          <span class="num grid size-12 shrink-0 place-items-center rounded-full bg-money-soft text-lg font-semibold text-money">{{ viewing.day_of_month }}</span>
          <div class="flex min-w-0 flex-1 flex-col gap-1">
            <MoneyAmount :amount="viewing.expected_amount" :currency="viewing.currency" sign="always" class="text-[2rem] leading-none font-semibold tracking-[-0.03em] text-money" />
            <span class="text-[0.9375rem] text-ink-2">{{ $t('details.monthlyOn', { day: viewing.day_of_month }) }}</span>
          </div>
        </section>
        <dl class="facts">
          <UiFact :label="$t('details.nextIncome')">
            {{ formatDayMonth(viewing.next_date, settings.locale) }}
            <template #hint>{{ inDays(viewing.next_date) }}</template>
          </UiFact>
          <UiFact :label="$t('income.account')">{{ (viewing.account_id && money.accountById.get(viewing.account_id)?.name) || $t('income.anyAccount') }}</UiFact>
          <UiFact v-if="!viewing.active" :label="$t('details.status')">{{ $t('details.paused') }}</UiFact>
        </dl>
        <p class="text-caption text-ink-3">{{ $t('details.incomeHint') }}</p>
      </div>
      <template v-if="viewing" #footer>
        <UiButton variant="secondary" block @click="editViewed">
          <template #icon><Pencil class="size-[18px]" :stroke-width="2.2" /></template>
          {{ $t('details.edit') }}
        </UiButton>
      </template>
    </UiSheet>

    <BottomSheet v-model:open="sheetOpen" :title="editing ? editing.name : $t('income.new')">
      <label class="flex flex-col gap-2">
        <span class="field-label">{{ $t('accounts.name') }}</span>
        <input v-model="name" maxlength="60" class="field" />
      </label>
      <label class="flex flex-col gap-2">
        <span class="field-label">{{ $t('income.day') }}</span>
        <input v-model.number="day" type="number" min="1" max="31" inputmode="numeric" class="field num w-28 text-lg font-semibold" />
        <span class="text-caption text-ink-3">{{ $t('income.dayHint') }}</span>
      </label>
      <section class="flex flex-col gap-2">
        <span class="field-label">{{ $t('accounts.currency') }}</span>
        <UiSegmented v-model="currency" :options="currencyOptions" :label="$t('accounts.currency')" />
      </section>
      <AmountField v-model="amount" :currency="currency" :label="$t('income.amount')" />
      <section class="flex flex-col gap-2">
        <span class="field-label">{{ $t('income.account') }}</span>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="chip" :class="{ 'chip-on': accountId === null }" :aria-pressed="accountId === null" @click="accountId = null">{{ $t('income.anyAccount') }}</button>
          <button
            v-for="item in money.activeAccounts"
            :key="item.id"
            type="button"
            class="chip"
            :class="{ 'chip-on': accountId === item.id }"
            :aria-pressed="accountId === item.id"
            @click="accountId = item.id"
          >
            {{ item.name }}
          </button>
        </div>
      </section>
      <template #footer>
        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
        <div class="flex gap-2">
          <UiButton v-if="editing" variant="danger-soft" class="!w-12 !px-0" :aria-label="$t('quickAdd.delete')" @click="remove">
            <Trash2 class="size-5" />
          </UiButton>
          <UiButton class="flex-1" :disabled="!canSave" :loading="saving" @click="save">{{ $t('accounts.save') }}</UiButton>
        </div>
      </template>
    </BottomSheet>
  </div>
</template>
