<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { budgetHint, limitHint } from '@/lib/advisor'
import { formatMoney } from '@/lib/format'
import { useAdvisor } from '@/stores/advisor'
import { useMoney } from '@/stores/money'
import { usePlan } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

/**
 * At the bottom of quick add, above «Записать»: what this expense does to today's limit and to the
 * category budget, before it is saved (docs/PLAN.md, 2.4). Shows nothing when it would not be true, such as
 * for an account that is not money for spending.
 */
const props = defineProps<{ amountUzs: number | null; categoryId: number | null; oneOff: boolean }>()

const advisor = useAdvisor()
const money = useMoney()
const plan = usePlan()
const settings = useSettings()
const { t } = useI18n()

// Fresh numbers each time the sheet opens and after every saved operation; a stale hint is worse than none.
function refresh(): void {
  plan.loadToday().catch(() => {})
  advisor.ensureBudget(true).catch(() => {})
}
onMounted(refresh)
watch(() => money.revision, refresh)

const sum = (value: number) => formatMoney(value, 'UZS', settings.locale)

const limit = computed(() => {
  const limitNow = plan.today?.limit
  if (!props.amountUzs || !limitNow || !plan.today?.has_accounts) return null
  return limitHint(props.amountUzs, limitNow, props.oneOff)
})

const budget = computed(() => {
  if (!props.amountUzs || !props.categoryId) return null
  const category = money.categoryById.get(props.categoryId)
  const root = category ? money.rootOf(category) : undefined
  const item = root ? advisor.budget?.items.find((i) => i.category_id === root.id) : undefined
  if (!root || !item) return null
  const hint = budgetHint(props.amountUzs, item)
  const name = root.names[settings.locale]
  return {
    text: hint.kind === 'budget' ? t('quickHint.budget', { category: name, percent: hint.percent }) : t('quickHint.budgetOver', { category: name, amount: sum(hint.amount) }),
    warn: hint.kind === 'budgetOver' || hint.percent >= advisor.alertPercent,
  }
})

const tone = computed(() => {
  const kind = limit.value?.kind
  return kind === 'short' ? 'text-danger' : kind === 'overToday' || kind === 'limitDrops' ? 'text-habit' : 'text-ink-3'
})
</script>

<template>
  <Transition name="hint">
    <p v-if="limit || budget" class="num flex flex-wrap items-center gap-x-2 gap-y-0.5 pb-2.5 text-caption" aria-live="polite">
      <span v-if="limit" :class="tone">{{ $t(`quickHint.${limit.kind}`, { amount: sum(limit.amount) }) }}</span>
      <span v-if="limit && budget" class="text-ink-3" aria-hidden="true">·</span>
      <span v-if="budget" :class="budget.warn ? 'text-habit' : 'text-ink-3'">{{ budget.text }}</span>
    </p>
  </Transition>
</template>

<style scoped>
.hint-enter-active {
  transition:
    opacity var(--dur-base) var(--ease-standard),
    transform var(--dur-spring) var(--ease-spring);
}
.hint-leave-active {
  transition: opacity var(--dur-fast) var(--ease-exit);
}
.hint-enter-from {
  opacity: 0;
  transform: translateY(-4px);
}
.hint-leave-to {
  opacity: 0;
}
</style>
