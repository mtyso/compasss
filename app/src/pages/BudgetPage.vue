<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import PageHeader from '@/components/PageHeader.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import { showToast } from '@/components/ui/toast'
import { groupDigits, groupInput } from '@/lib/digits'
import { haptics } from '@/lib/haptics'
import { isoMonth, shiftMonth } from '@/lib/dates'
import { formatMoney, formatMonthTitle, formatNumber } from '@/lib/format'
import { amountToInput, parseAmount, toUzs } from '@/lib/money'
import { useMoney } from '@/stores/money'
import { usePlan, type Budget } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

const money = useMoney()
const plan = usePlan()
const settings = useSettings()
const { t } = useI18n()

const month = ref(isoMonth())
const budget = ref<Budget | null>(null)
const texts = ref<Record<number, string>>({})
const saving = ref(false)
const error = ref<string | null>(null)

const monthTitle = computed(() => formatMonthTitle(month.value, settings.locale))

async function load(): Promise<void> {
  await Promise.all([money.ensureLoaded(), plan.incomes.length ? null : plan.loadIncomes()])
  budget.value = await plan.loadBudget(month.value)
  texts.value = Object.fromEntries(budget.value.items.map((item) => [item.category_id, groupDigits(amountToInput(item.limit_uzs, 'UZS'))]))
}

onMounted(load)
watch(month, load)

const rows = computed(() =>
  money.topCategories('expense').map((category) => {
    const item = budget.value?.items.find((i) => i.category_id === category.id)
    return { category, spent: item?.spent_uzs ?? 0 }
  }),
)

// Empty fields go too, as zero: the server then knows the month is set and does not refill it from the last one.
const limits = computed(() =>
  Object.entries(texts.value).map(([id, text]) => ({ category_id: Number(id), limit_uzs: text.trim() === '' ? 0 : (parseAmount(text, 'UZS') ?? -1) })),
)
const invalid = computed(() => limits.value.some((item) => item.limit_uzs < 0))
const totalLimit = computed(() => limits.value.reduce((sum, item) => sum + Math.max(0, item.limit_uzs), 0))
const expectedIncome = computed(() =>
  plan.incomes.filter((s) => s.active).reduce((sum, s) => sum + toUzs(s.expected_amount, s.currency, money.rates[s.currency] ?? (s.currency === 'UZS' ? 1 : 0)), 0),
)

async function save(): Promise<void> {
  if (invalid.value) return
  saving.value = true
  error.value = null
  try {
    budget.value = await plan.saveBudget(month.value, limits.value)
    haptics.notify('success')
    showToast({ message: t('budget.saved'), detail: monthTitle.value, tone: 'success' })
  } catch {
    haptics.notify('error')
    error.value = t('quickAdd.failed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page">
    <PageHeader :title="$t('budget.title')">
      <div class="-mr-2 flex items-center">
        <UiIconButton size="sm" :label="$t('money.prevMonth')" @click="month = shiftMonth(month, -1)">
          <ChevronLeft class="size-5" />
        </UiIconButton>
        <span class="min-w-[6.5rem] text-center text-sm font-semibold">{{ monthTitle }}</span>
        <UiIconButton size="sm" :label="$t('money.nextMonth')" @click="month = shiftMonth(month, 1)">
          <ChevronRight class="size-5" />
        </UiIconButton>
      </div>
    </PageHeader>

    <template v-if="!budget">
      <div class="skeleton h-28 rounded-card" />
      <div class="skeleton h-96 rounded-card" />
    </template>

    <template v-else>
      <section class="flex flex-col gap-1.5">
        <span class="text-[0.9375rem] font-medium text-ink-2">{{ $t('budget.total') }}</span>
        <MoneyAmount :amount="totalLimit" animate class="text-[2.25rem] leading-none font-semibold tracking-[-0.03em]" />
        <span v-if="expectedIncome" class="num pt-1 text-caption" :class="totalLimit > expectedIncome ? 'text-danger' : 'text-ink-3'">
          {{ $t('budget.ofIncome', { income: formatMoney(expectedIncome, 'UZS', settings.locale), percent: Math.round((totalLimit / expectedIncome) * 100) }) }}
        </span>
        <RouterLink v-else :to="{ name: 'income' }" class="press self-start pt-1 text-caption font-medium text-money">{{ $t('today.addIncome') }}</RouterLink>
      </section>

      <section class="flex flex-col gap-2.5">
        <p class="text-caption text-ink-3">{{ $t('budget.intro') }}</p>
        <div class="group">
          <label v-for="row in rows" :key="row.category.id" class="flex min-h-14 items-center gap-3 px-4 py-2.5">
            <CategoryIcon :icon="row.category.icon" :color="row.category.color" size="sm" />
            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
              <span class="truncate font-medium">{{ row.category.names[settings.locale] }}</span>
              <span v-if="row.spent" class="num truncate text-caption text-ink-3">{{ $t('budget.spent', { amount: formatNumber(row.spent, 'UZS') }) }}</span>
            </span>
            <input
              :value="texts[row.category.id] ?? ''"
              inputmode="decimal"
              autocomplete="off"
              :placeholder="$t('budget.noLimit')"
              class="num w-32 rounded-control bg-surface-2 px-3 py-2 text-right text-sm font-semibold outline-none placeholder:font-normal placeholder:text-ink-3 focus:shadow-[inset_0_0_0_1.5px_var(--c-ink-3)]"
              :class="{ '!shadow-[inset_0_0_0_1.5px_var(--c-danger)]': texts[row.category.id] && parseAmount(texts[row.category.id]!, 'UZS') === null }"
              @input="groupInput($event, (value) => (texts[row.category.id] = value))"
            />
          </label>
        </div>
      </section>

      <!-- Pinned to the bottom of the screen while the list scrolls under it. -->
      <div class="sticky bottom-0 -mx-4 -mb-4 flex flex-col gap-2 bg-gradient-to-t from-bg from-60% px-4 pt-6 pb-4">
        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
        <UiButton size="lg" block :disabled="invalid" :loading="saving" @click="save">{{ $t('budget.save') }}</UiButton>
      </div>
    </template>
  </div>
</template>
