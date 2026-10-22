<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Loader2, Plus, Trash2 } from 'lucide-vue-next'
import AmountField from '@/components/AmountField.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import PageHeader from '@/components/PageHeader.vue'
import PaymentRow from '@/components/plan/PaymentRow.vue'
import { ApiError } from '@/lib/api'
import { isoDate } from '@/lib/dates'
import { formatMoney, type Currency } from '@/lib/format'
import { CURRENCIES, toUzs } from '@/lib/money'
import { useMoney } from '@/stores/money'
import { usePlan, type RecurringPayment } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

const plan = usePlan()
const money = useMoney()
const settings = useSettings()
const { t } = useI18n()

const sheetOpen = ref(false)
const editing = ref<RecurringPayment | null>(null)
const name = ref('')
const amount = ref<number | null>(null)
const currency = ref<Currency>('UZS')
const categoryId = ref<number | null>(null)
const accountId = ref<number | null>(null)
const day = ref(1)
const nextDue = ref('')
const saving = ref(false)
const error = ref<string | null>(null)

onMounted(() => Promise.all([plan.loadPayments(), money.ensureLoaded()]))

const monthlyUzs = computed(() =>
  plan.payments
    .filter((p) => p.active)
    .reduce((sum, p) => sum + toUzs(p.amount, p.currency, money.rates[p.currency] ?? (p.currency === 'UZS' ? 1 : 0)), 0),
)

const categoryGroups = computed(() =>
  money.topCategories('expense').map((parent) => ({ parent, children: money.childCategories(parent.id) })),
)

function open(payment: RecurringPayment | null): void {
  editing.value = payment
  name.value = payment?.name ?? ''
  amount.value = payment?.amount ?? null
  currency.value = payment?.currency ?? 'UZS'
  categoryId.value = payment?.category_id ?? null
  accountId.value = payment?.account_id ?? money.activeAccounts[0]?.id ?? null
  day.value = payment?.day_of_month ?? Number(isoDate().slice(8, 10))
  nextDue.value = payment?.next_due_on ?? ''
  error.value = null
  sheetOpen.value = true
}

function pickAccount(id: number): void {
  accountId.value = id
  // A payment usually goes out in its account's currency.
  if (!editing.value) currency.value = money.accountById.get(id)?.currency ?? currency.value
}

const canSave = computed(() => name.value.trim() !== '' && !!amount.value && !!categoryId.value && day.value >= 1 && day.value <= 31)

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    await plan.savePayment(
      {
        name: name.value.trim(),
        amount: amount.value!,
        currency: currency.value,
        category_id: categoryId.value!,
        account_id: accountId.value,
        day_of_month: day.value,
        ...(editing.value && nextDue.value ? { next_due_on: nextDue.value } : {}),
      },
      editing.value?.id,
    )
    sheetOpen.value = false
  } catch (e) {
    error.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('quickAdd.failed')
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  if (!editing.value) return
  await plan.deletePayment(editing.value.id)
  sheetOpen.value = false
}
</script>

<template>
  <div class="flex flex-col gap-5">
    <PageHeader :title="$t('payments.title')" />
    <p class="px-1 text-sm text-ink-2">{{ $t('payments.intro') }}</p>

    <article v-if="plan.payments.length" class="flex items-baseline justify-between rounded-3xl bg-surface px-5 py-4 shadow-sm">
      <span class="text-sm text-ink-2">{{ $t('payments.monthly') }}</span>
      <span class="num font-display text-xl font-bold">{{ formatMoney(monthlyUzs, 'UZS', settings.locale) }}</span>
    </article>

    <div class="flex flex-col divide-y divide-line overflow-hidden rounded-3xl bg-surface shadow-sm">
      <PaymentRow
        v-for="payment in plan.payments"
        :key="payment.id"
        :payment="payment"
        :class="{ 'opacity-50': !payment.active }"
        @open="open(payment)"
        @edit="open(payment)"
      />
      <button type="button" class="flex items-center gap-3 px-4 py-3 text-ink-2 active:bg-bg" @click="open(null)">
        <Plus class="size-5" />
        {{ $t('payments.add') }}
      </button>
    </div>

    <BottomSheet v-model:open="sheetOpen" :title="editing ? editing.name : $t('payments.new')">
      <label class="flex flex-col gap-1.5">
        <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('accounts.name') }}</span>
        <input v-model="name" maxlength="60" :placeholder="$t('payments.namePlaceholder')" class="field" />
      </label>
      <section class="flex flex-col gap-1.5">
        <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('payments.fromAccount') }}</span>
        <div class="flex flex-wrap gap-2">
          <button v-for="item in money.activeAccounts" :key="item.id" type="button" class="chip" :class="{ 'chip-on': accountId === item.id }" @click="pickAccount(item.id)">
            {{ item.name }}
          </button>
        </div>
      </section>
      <section class="flex flex-col gap-1.5">
        <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('accounts.currency') }}</span>
        <div class="flex gap-2">
          <button v-for="item in CURRENCIES" :key="item" type="button" class="chip" :class="{ 'chip-on': currency === item }" @click="currency = item">
            {{ item === 'UZS' ? $t('accounts.som') : $t('accounts.dollar') }}
          </button>
        </div>
      </section>
      <AmountField v-model="amount" :currency="currency" :label="$t('quickAdd.amount')" />
      <label class="flex flex-col gap-1.5">
        <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('quickAdd.category') }}</span>
        <select v-model="categoryId" class="field">
          <option :value="null" disabled>{{ $t('payments.pickCategory') }}</option>
          <optgroup v-for="group in categoryGroups" :key="group.parent.id" :label="group.parent.names[settings.locale]">
            <option :value="group.parent.id">{{ group.parent.names[settings.locale] }}</option>
            <option v-for="child in group.children" :key="child.id" :value="child.id">— {{ child.names[settings.locale] }}</option>
          </optgroup>
        </select>
      </label>
      <label class="flex flex-col gap-1.5">
        <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('income.day') }}</span>
        <input v-model.number="day" type="number" min="1" max="31" inputmode="numeric" class="field num w-28" />
      </label>
      <label v-if="editing" class="flex flex-col gap-1.5">
        <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('payments.nextDue') }}</span>
        <input v-model="nextDue" type="date" class="field w-48" />
        <span class="px-1 text-xs text-ink-3">{{ $t('payments.nextDueHint') }}</span>
      </label>
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
