<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Lightbulb, ListChecks, Repeat, Scale, Wallet } from 'lucide-vue-next'
import InsightCard from '@/components/advisor/InsightCard.vue'
import SectionBlock from '@/components/SectionBlock.vue'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiRow from '@/components/ui/UiRow.vue'
import LimitCard from '@/components/plan/LimitCard.vue'
import PaymentRow from '@/components/plan/PaymentRow.vue'
import { formatDayHeading } from '@/lib/format'
import { useMoney } from '@/stores/money'
import { usePlan } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

const settings = useSettings()
const money = useMoney()
const plan = usePlan()
const router = useRouter()
const failed = ref(false)

const heading = computed(() => formatDayHeading(new Date(), settings.locale))
const today = computed(() => plan.today)

async function load(): Promise<void> {
  try {
    await Promise.all([plan.loadToday(), money.ensureLoaded()])
    failed.value = false
  } catch {
    failed.value = true
  }
}

onMounted(load)
watch(() => money.revision, load)
</script>

<template>
  <div class="page">
    <ScreenHeader :title="$t('today.title')" :overline="heading" />

    <div v-if="!today && !failed" class="flex flex-col gap-6" aria-hidden="true">
      <div class="skeleton h-52 !rounded-hero" />
      <div class="skeleton h-28" />
    </div>
    <p v-else-if="failed && !today" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <template v-else-if="today">
      <UiEmpty v-if="!today.has_accounts" :icon="Wallet" tone="money" :title="$t('today.limit')" :text="$t('today.limitEmpty')">
        <UiButton :to="{ name: 'account-new' }" size="sm">{{ $t('quickAdd.addAccount') }}</UiButton>
      </UiEmpty>
      <LimitCard v-else :limit="today.limit" :income-name="today.next_income?.name" />

      <SectionBlock v-if="today.payments.length" :title="$t('today.payments')">
        <div class="group">
          <PaymentRow
            v-for="payment in today.payments"
            :key="payment.id"
            :payment="payment"
            @open="router.push({ name: 'payments' })"
            @edit="router.push({ name: 'payments' })"
          />
        </div>
      </SectionBlock>

      <section class="flex flex-col gap-2.5">
        <div class="flex items-baseline justify-between gap-3">
          <h2 class="section-title">{{ $t('today.advice') }}</h2>
          <RouterLink :to="{ name: 'advice' }" class="press px-1 text-caption font-medium text-ink-2">
            {{ today.insights_new > today.insights.length ? $t('today.allAdviceNew', { n: today.insights_new }) : $t('today.allAdvice') }}
          </RouterLink>
        </div>
        <TransitionGroup name="list" tag="div" class="relative flex flex-col gap-3">
          <InsightCard v-for="insight in today.insights" :key="insight.id" :insight="insight" compact />
        </TransitionGroup>
        <UiEmpty v-if="!today.insights.length" :icon="Lightbulb" tone="money" :text="$t('today.adviceEmpty')" />
        <div class="group">
          <UiRow :to="{ name: 'can-afford' }" :title="$t('advice.canAfford')" :subtitle="$t('advice.canAffordHint')">
            <template #leading>
              <span class="grid size-9 place-items-center rounded-xl bg-money-soft text-money"><Scale class="size-5" :stroke-width="2" /></span>
            </template>
          </UiRow>
        </div>
      </section>
    </template>

    <SectionBlock :title="$t('today.habits')" :empty="$t('today.habitsEmpty')" :icon="Repeat" tone="habit" />
    <SectionBlock :title="$t('today.tasks')" :empty="$t('today.tasksEmpty')" :icon="ListChecks" tone="task" />
  </div>
</template>

<style scoped>
.list-move,
.list-enter-active {
  transition:
    opacity var(--dur-base) var(--ease-standard),
    transform var(--dur-spring) var(--ease-spring);
}
.list-leave-active {
  position: absolute;
  inset-inline: 0;
  transition:
    opacity var(--dur-fast) var(--ease-exit),
    transform var(--dur-fast) var(--ease-exit);
}
.list-enter-from {
  opacity: 0;
  transform: translateY(8px);
}
.list-leave-to {
  opacity: 0;
  transform: translateX(24px);
}
</style>
