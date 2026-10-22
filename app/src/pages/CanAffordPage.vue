<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, CircleAlert, CircleCheck, Hourglass, OctagonX } from 'lucide-vue-next'
import AmountField from '@/components/AmountField.vue'
import PageHeader from '@/components/PageHeader.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import { ApiError } from '@/lib/api'
import type { Currency } from '@/lib/format'
import { haptics } from '@/lib/haptics'
import { useAdvisor, type PurchaseVerdict, type Verdict } from '@/stores/advisor'
import { useMoney } from '@/stores/money'
import { useSettings } from '@/stores/settings'

const advisor = useAdvisor()
const money = useMoney()
const settings = useSettings()
const { t } = useI18n()

const amount = ref<number | null>(null)
const currency = ref<Currency>('UZS')
const categoryId = ref<number | null>(null)
const checking = ref(false)
const result = ref<PurchaseVerdict | null>(null)
const error = ref<string | null>(null)

onMounted(() => money.ensureLoaded())

const currencies = computed(() => [
  { value: 'UZS' as Currency, label: t('accounts.som') },
  { value: 'USD' as Currency, label: t('accounts.dollar') },
])
const categories = computed(() => money.topCategories('expense'))

// The answer belongs to the question: change the question and the old answer goes.
watch([amount, currency, categoryId], () => {
  result.value = null
  error.value = null
})

async function check(): Promise<void> {
  if (!amount.value) return
  checking.value = true
  error.value = null
  try {
    result.value = await advisor.canAfford({ amount: amount.value, currency: currency.value, category_id: categoryId.value })
    haptics.notify(result.value.verdict === 'yes' || result.value.verdict === 'yes_but' ? 'success' : 'warning')
  } catch (e) {
    error.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('quickAdd.failed')
  } finally {
    checking.value = false
  }
}

function pick(id: number | null): void {
  haptics.selection()
  categoryId.value = categoryId.value === id ? null : id
}

// The verdict tints its panel and fills its icon, from calm green to red.
const LOOK: Record<Verdict, { icon: typeof CircleCheck; tone: string; panel: string }> = {
  yes: { icon: CircleCheck, tone: 'bg-money text-on-money', panel: 'bg-money-soft' },
  yes_but: { icon: CircleAlert, tone: 'bg-task text-bg', panel: 'bg-task-soft' },
  wait: { icon: Hourglass, tone: 'bg-habit text-bg', panel: 'bg-habit-soft' },
  no: { icon: OctagonX, tone: 'bg-danger text-bg', panel: 'bg-danger-soft' },
}
</script>

<template>
  <div class="page">
    <PageHeader :title="$t('canAfford.title')" back="/advice" />
    <p class="-mt-3 text-sm text-ink-2">{{ $t('canAfford.intro') }}</p>

    <form class="flex flex-col gap-5" @submit.prevent="check">
      <AmountField v-model="amount" :currency="currency" :label="$t('canAfford.amount')" />
      <UiSegmented v-model="currency" :options="currencies" :label="$t('accounts.currency')" size="sm" />

      <section class="flex flex-col gap-2.5">
        <h2 class="field-label">{{ $t('canAfford.category') }}</h2>
        <div class="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 py-0.5">
          <button
            v-for="item in categories"
            :key="item.id"
            type="button"
            class="chip"
            :class="{ 'chip-on': categoryId === item.id }"
            :aria-pressed="categoryId === item.id"
            @click="pick(item.id)"
          >
            {{ item.names[settings.locale] }}
          </button>
        </div>
      </section>

      <UiButton type="submit" size="lg" block :loading="checking" :disabled="!amount">{{ $t('canAfford.check') }}</UiButton>
      <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
    </form>

    <Transition name="verdict">
      <article v-if="result" :key="`${result.verdict}-${result.amount_uzs}`" class="flex flex-col gap-4 rounded-hero p-5" :class="LOOK[result.verdict].panel" aria-live="polite">
        <div class="flex items-center gap-3">
          <span class="verdict-icon grid size-12 shrink-0 place-items-center rounded-full" :class="LOOK[result.verdict].tone">
            <component :is="LOOK[result.verdict].icon" class="size-6" :stroke-width="2.2" />
          </span>
          <div class="flex min-w-0 flex-col">
            <h2 class="text-headline font-semibold">{{ result.title }}</h2>
            <MoneyAmount :amount="result.amount_uzs" class="text-caption text-ink-3" />
          </div>
        </div>

        <div v-if="(result.verdict === 'yes_but' || result.verdict === 'wait') && result.limit_after_uzs !== result.limit_before_uzs" class="flex items-center gap-3 rounded-control bg-bg px-4 py-3">
          <div class="flex flex-col">
            <span class="text-micro text-ink-3">{{ $t('canAfford.limitNow') }}</span>
            <MoneyAmount :amount="result.limit_before_uzs" class="font-semibold" />
          </div>
          <ArrowRight class="size-4 shrink-0 text-ink-3" />
          <div class="flex flex-col">
            <span class="text-micro text-ink-3">{{ $t('canAfford.limitAfter') }}</span>
            <MoneyAmount :amount="result.limit_after_uzs" class="font-semibold" :class="result.verdict === 'wait' ? 'text-habit' : 'text-ink'" />
          </div>
        </div>

        <ul class="flex flex-col gap-2">
          <li v-for="(reason, i) in result.reasons" :key="i" class="flex gap-2.5 text-sm text-ink-2">
            <span class="mt-2 size-1.5 shrink-0 rounded-full bg-ink-3" aria-hidden="true" />
            {{ reason }}
          </li>
        </ul>
      </article>
    </Transition>
  </div>
</template>

<style scoped>
.verdict-enter-active {
  transition:
    opacity var(--dur-base) var(--ease-standard),
    transform var(--dur-spring) var(--ease-spring);
}
.verdict-enter-active .verdict-icon {
  transition: transform var(--dur-pop) var(--ease-pop) 80ms;
}
.verdict-leave-active {
  transition: opacity var(--dur-fast) var(--ease-exit);
}
.verdict-enter-from {
  opacity: 0;
  transform: translateY(12px) scale(0.98);
}
.verdict-enter-from .verdict-icon {
  transform: scale(0.6);
}
.verdict-leave-to {
  opacity: 0;
}
</style>
