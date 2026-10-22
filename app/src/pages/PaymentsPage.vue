<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2 } from 'lucide-vue-next'
import AmountField from '@/components/AmountField.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import PageHeader from '@/components/PageHeader.vue'
import PaymentRow from '@/components/plan/PaymentRow.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiRow from '@/components/ui/UiRow.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import { haptics } from '@/lib/haptics'
import { ApiError } from '@/lib/api'
import { isoDate } from '@/lib/dates'
import type { Currency } from '@/lib/format'
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
const loaded = ref(false)

onMounted(async () => {
  await Promise.all([plan.loadPayments(), money.ensureLoaded()]).catch(() => {})
  loaded.value = true
})

const currencyOptions = computed(() => CURRENCIES.map((value) => ({ value, label: value === 'UZS' ? t('accounts.som') : t('accounts.dollar') })))

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
  haptics.selection()
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
    haptics.notify('success')
  } catch (e) {
    haptics.notify('error')
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
  <div class="page">
    <PageHeader :title="$t('payments.title')" />

    <template v-if="!loaded">
      <div class="skeleton h-20 rounded-card" />
      <div class="skeleton h-40 rounded-card" />
    </template>

    <template v-else>
      <section v-if="plan.payments.length" class="flex flex-col gap-1.5">
        <span class="text-[0.9375rem] font-medium text-ink-2">{{ $t('payments.monthly') }}</span>
        <MoneyAmount :amount="monthlyUzs" animate class="text-[2.25rem] leading-none font-semibold tracking-[-0.03em]" />
      </section>

      <section class="flex flex-col gap-2.5">
        <p class="text-caption text-ink-3">{{ $t('payments.intro') }}</p>
        <div class="group">
          <PaymentRow
            v-for="payment in plan.payments"
            :key="payment.id"
            :payment="payment"
            :class="{ 'opacity-50': !payment.active }"
            @open="open(payment)"
            @edit="open(payment)"
          />
          <UiRow :title="$t('payments.add')" tone="muted" :chevron="false" @click="open(null)">
            <template #leading>
              <span class="grid size-9 place-items-center text-ink-3"><Plus class="size-5" :stroke-width="2.2" /></span>
            </template>
          </UiRow>
        </div>
      </section>
    </template>

    <BottomSheet v-model:open="sheetOpen" :title="editing ? editing.name : $t('payments.new')">
      <label class="flex flex-col gap-2">
        <span class="field-label">{{ $t('accounts.name') }}</span>
        <input v-model="name" maxlength="60" :placeholder="$t('payments.namePlaceholder')" class="field" />
      </label>
      <section class="flex flex-col gap-2">
        <span class="field-label">{{ $t('payments.fromAccount') }}</span>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="item in money.activeAccounts"
            :key="item.id"
            type="button"
            class="chip"
            :class="{ 'chip-on': accountId === item.id }"
            :aria-pressed="accountId === item.id"
            @click="pickAccount(item.id)"
          >
            {{ item.name }}
          </button>
        </div>
      </section>
      <section class="flex flex-col gap-2">
        <span class="field-label">{{ $t('accounts.currency') }}</span>
        <UiSegmented v-model="currency" :options="currencyOptions" :label="$t('accounts.currency')" />
      </section>
      <AmountField v-model="amount" :currency="currency" :label="$t('quickAdd.amount')" />
      <label class="flex flex-col gap-2">
        <span class="field-label">{{ $t('quickAdd.category') }}</span>
        <select v-model="categoryId" class="field">
          <option :value="null" disabled>{{ $t('payments.pickCategory') }}</option>
          <optgroup v-for="group in categoryGroups" :key="group.parent.id" :label="group.parent.names[settings.locale]">
            <option :value="group.parent.id">{{ group.parent.names[settings.locale] }}</option>
            <option v-for="child in group.children" :key="child.id" :value="child.id">— {{ child.names[settings.locale] }}</option>
          </optgroup>
        </select>
      </label>
      <label class="flex flex-col gap-2">
        <span class="field-label">{{ $t('income.day') }}</span>
        <input v-model.number="day" type="number" min="1" max="31" inputmode="numeric" class="field num w-28 text-lg font-semibold" />
      </label>
      <label v-if="editing" class="flex flex-col gap-2">
        <span class="field-label">{{ $t('payments.nextDue') }}</span>
        <input v-model="nextDue" type="date" class="field w-48" />
        <span class="text-caption text-ink-3">{{ $t('payments.nextDueHint') }}</span>
      </label>
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
