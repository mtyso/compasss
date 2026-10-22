<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Loader2 } from 'lucide-vue-next'
import SectionBlock from '@/components/SectionBlock.vue'
import LimitCard from '@/components/plan/LimitCard.vue'
import PaymentRow from '@/components/plan/PaymentRow.vue'
import { formatDayHeading, formatDayMonth, formatMoney } from '@/lib/format'
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
  <div class="flex flex-col gap-5">
    <header class="flex flex-col gap-0.5 px-1">
      <span class="text-sm text-ink-3">{{ heading }}</span>
      <h1 class="font-display text-2xl font-bold">{{ $t('today.title') }}</h1>
    </header>

    <div v-if="!today && !failed" class="grid place-items-center py-10"><Loader2 class="size-6 animate-spin text-ink-3" /></div>
    <p v-else-if="failed && !today" class="rounded-2xl bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <template v-else-if="today">
      <article v-if="!today.has_accounts" class="flex flex-col items-start gap-3 rounded-3xl bg-surface p-5 shadow-sm">
        <span class="text-[11px] font-semibold tracking-wider text-ink-3 uppercase">{{ $t('today.limit') }}</span>
        <p class="text-sm text-ink-2">{{ $t('today.limitEmpty') }}</p>
        <RouterLink :to="{ name: 'account-new' }" class="rounded-2xl bg-ink px-4 py-2.5 text-sm font-semibold text-surface">{{ $t('quickAdd.addAccount') }}</RouterLink>
      </article>
      <LimitCard v-else :limit="today.limit" :income-name="today.next_income?.name" />

      <SectionBlock v-if="today.payments.length" :title="$t('today.payments')">
        <div class="flex flex-col divide-y divide-line overflow-hidden rounded-3xl bg-surface shadow-sm">
          <PaymentRow
            v-for="payment in today.payments"
            :key="payment.id"
            :payment="payment"
            @open="router.push({ name: 'payments' })"
            @edit="router.push({ name: 'payments' })"
          />
        </div>
      </SectionBlock>

      <p v-if="today.next_income" class="px-1 text-sm text-ink-2">
        {{ $t('today.nextIncome', {
          name: today.next_income.name,
          date: formatDayMonth(today.next_income.next_date, settings.locale),
          amount: formatMoney(today.next_income.expected_amount, today.next_income.currency, settings.locale),
        }) }}
      </p>
    </template>

    <SectionBlock :title="$t('today.advice')" :empty="$t('today.adviceEmpty')" />
    <SectionBlock :title="$t('today.habits')" :empty="$t('today.habitsEmpty')" />
    <SectionBlock :title="$t('today.tasks')" :empty="$t('today.tasksEmpty')" />
  </div>
</template>
