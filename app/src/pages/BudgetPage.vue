<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronLeft, ChevronRight, Loader2 } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import PageHeader from '@/components/PageHeader.vue'
import { isoMonth, shiftMonth } from '@/lib/dates'
import { formatMoney, formatMonthTitle } from '@/lib/format'
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
const saved = ref(false)
const error = ref<string | null>(null)

const monthTitle = computed(() => formatMonthTitle(month.value, settings.locale))

async function load(): Promise<void> {
  await Promise.all([money.ensureLoaded(), plan.incomes.length ? null : plan.loadIncomes()])
  budget.value = await plan.loadBudget(month.value)
  texts.value = Object.fromEntries(budget.value.items.map((item) => [item.category_id, amountToInput(item.limit_uzs, 'UZS')]))
}

onMounted(load)
watch(month, load)

const rows = computed(() =>
  money.topCategories('expense').map((category) => {
    const item = budget.value?.items.find((i) => i.category_id === category.id)
    return { category, spent: item?.spent_uzs ?? 0 }
  }),
)

const limits = computed(() =>
  Object.entries(texts.value)
    .map(([id, text]) => ({ category_id: Number(id), limit_uzs: text.trim() === '' ? 0 : (parseAmount(text, 'UZS') ?? -1) }))
    .filter((item) => item.limit_uzs !== 0),
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
    saved.value = true
    window.setTimeout(() => (saved.value = false), 2000)
  } catch {
    error.value = t('quickAdd.failed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-5 pb-20">
    <PageHeader :title="$t('budget.title')">
      <div class="flex items-center">
        <button type="button" class="grid size-8 place-items-center rounded-full active:bg-line" :aria-label="$t('money.prevMonth')" @click="month = shiftMonth(month, -1)">
          <ChevronLeft class="size-5" />
        </button>
        <button type="button" class="grid size-8 place-items-center rounded-full active:bg-line" :aria-label="$t('money.nextMonth')" @click="month = shiftMonth(month, 1)">
          <ChevronRight class="size-5" />
        </button>
      </div>
    </PageHeader>

    <div v-if="!budget" class="grid place-items-center py-16"><Loader2 class="size-6 animate-spin text-ink-3" /></div>

    <template v-else>
      <article class="flex flex-col gap-1 rounded-3xl bg-surface p-5 shadow-sm">
        <span class="text-xs font-semibold text-ink-3">{{ monthTitle }}</span>
        <span class="num font-display text-2xl font-bold">{{ formatMoney(totalLimit, 'UZS', settings.locale) }}</span>
        <span v-if="expectedIncome" class="num text-xs" :class="totalLimit > expectedIncome ? 'text-danger' : 'text-ink-3'">
          {{ $t('budget.ofIncome', { income: formatMoney(expectedIncome, 'UZS', settings.locale), percent: Math.round((totalLimit / expectedIncome) * 100) }) }}
        </span>
        <RouterLink v-else :to="{ name: 'income' }" class="text-xs font-medium text-money">{{ $t('today.addIncome') }}</RouterLink>
      </article>

      <p class="px-1 text-xs text-ink-3">{{ $t('budget.intro') }}</p>

      <div class="flex flex-col divide-y divide-line overflow-hidden rounded-3xl bg-surface shadow-sm">
        <label v-for="row in rows" :key="row.category.id" class="flex items-center gap-3 px-4 py-2.5">
          <CategoryIcon :icon="row.category.icon" :color="row.category.color" size="sm" />
          <span class="flex min-w-0 flex-1 flex-col">
            <span class="truncate text-sm">{{ row.category.names[settings.locale] }}</span>
            <span v-if="row.spent" class="num text-[11px] text-ink-3">{{ $t('budget.spent', { amount: formatMoney(row.spent, 'UZS', settings.locale) }) }}</span>
          </span>
          <input
            v-model="texts[row.category.id]"
            inputmode="decimal"
            :placeholder="$t('budget.noLimit')"
            class="num w-32 rounded-xl border border-line bg-transparent px-3 py-2 text-right text-sm outline-none placeholder:text-ink-3 focus:border-ink-3"
            :class="{ '!border-danger': texts[row.category.id] && parseAmount(texts[row.category.id], 'UZS') === null }"
          />
        </label>
      </div>
    </template>

    <div class="fixed inset-x-0 bottom-20 z-10 mx-auto flex max-w-xl flex-col gap-1 px-4">
      <p v-if="error" class="text-sm text-danger">{{ error }}</p>
      <button
        type="button"
        class="flex items-center justify-center gap-2 rounded-2xl bg-ink py-3 font-semibold text-surface shadow-lg disabled:opacity-40"
        :disabled="!budget || invalid || saving"
        @click="save"
      >
        <Loader2 v-if="saving" class="size-5 animate-spin" />
        {{ saved ? $t('income.saved') : $t('budget.save') }}
      </button>
    </div>
  </div>
</template>
