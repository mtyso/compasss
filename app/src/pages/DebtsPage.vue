<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowDownLeft, ArrowUpRight, Handshake } from 'lucide-vue-next'
import PageHeader from '@/components/PageHeader.vue'
import BorrowSheet from '@/components/debts/BorrowSheet.vue'
import DebtPlan from '@/components/debts/DebtPlan.vue'
import LendSheet from '@/components/debts/LendSheet.vue'
import SettleSheet from '@/components/debts/SettleSheet.vue'
import AccountIcon from '@/components/money/AccountIcon.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiRow from '@/components/ui/UiRow.vue'
import { formatDayMonth, formatMoney, formatMonthUntil } from '@/lib/format'
import { useDebts, type OweRow, type OwedRow } from '@/stores/debts'
import { useMoney } from '@/stores/money'
import { useSettings } from '@/stores/settings'

const debts = useDebts()
const money = useMoney()
const settings = useSettings()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const loaded = ref(false)
const failed = ref(false)

onMounted(async () => {
  try {
    await Promise.all([debts.load(), money.ensureLoaded()])
  } catch {
    failed.value = true
  }
  loaded.value = true
  // From a reminder about money lent (?settle=account id).
  const id = Number(route.query.settle)
  const owed = debts.overview?.owed.find((r) => r.account_id === id)
  const owe = debts.overview?.owe.find((r) => r.account_id === id)
  if (owed) openOwed(owed)
  else if (owe) openOwe(owe)
  if (Object.keys(route.query).length) void router.replace({ query: {} })
})

const lendOpen = ref(false)
const lendTo = ref<OwedRow | null>(null)
const borrowOpen = ref(false)
const settleOpen = ref(false)
const owe = ref<OweRow | null>(null)
const owed = ref<OwedRow | null>(null)

function lend(person: OwedRow | null = null): void {
  lendTo.value = person
  lendOpen.value = true
}

function openOwe(row: OweRow): void {
  owe.value = row
  owed.value = null
  settleOpen.value = true
}

function openOwed(row: OwedRow): void {
  owed.value = row
  owe.value = null
  settleOpen.value = true
}

const fmt = (minor: number, row: { currency: OweRow['currency'] }) => formatMoney(minor, row.currency, settings.locale)

/** «24% · 400 000 сум/мес.», or «без плана»; the month it is paid off goes under the amount. */
function oweLine(row: OweRow): string {
  const parts = row.rate ? [t('debts.rate', { rate: row.rate })] : []
  if (!row.goal) return [...parts, t('debts.noPlan')].join(' · ')
  if (row.goal.monthly > 0) parts.push(t('debts.perMonth', { amount: fmt(row.goal.monthly, row) }))
  return parts.join(' · ')
}

const paidOff = (row: OweRow) =>
  row.goal?.paid_off_on ? t('debts.paidOff', { month: formatMonthUntil(row.goal.paid_off_on.slice(0, 7), settings.locale) }) : null

function owedLine(row: OwedRow): string {
  if (!row.due_on) return t('debts.noDue')
  return t(row.overdue ? 'debts.overdueSince' : 'debts.dueBy', { date: formatDayMonth(row.due_on, settings.locale) })
}
</script>

<template>
  <div class="page page-wide">
    <PageHeader :title="$t('debts.title')" />

    <template v-if="!loaded">
      <div class="skeleton h-24 rounded-card" />
      <div class="skeleton h-40 rounded-card" />
    </template>

    <p v-else-if="failed && !debts.overview" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <div v-else-if="debts.overview" class="page-columns">
      <div class="page-col">
        <section class="flex flex-col gap-4">
          <dl class="grid grid-cols-2 gap-4">
            <div class="flex min-w-0 flex-col gap-1.5">
              <dt class="text-[0.9375rem] font-medium text-ink-2">{{ $t('debts.iOwe') }}</dt>
              <dd><MoneyAmount :amount="debts.overview.owe_uzs" animate class="text-[clamp(1.25rem,6vw,1.75rem)] leading-none font-semibold tracking-[-0.03em]" /></dd>
            </div>
            <div class="flex min-w-0 flex-col gap-1.5">
              <dt class="text-[0.9375rem] font-medium text-ink-2">{{ $t('debts.owedToMe') }}</dt>
              <dd><MoneyAmount :amount="debts.overview.owed_uzs" animate class="text-[clamp(1.25rem,6vw,1.75rem)] leading-none font-semibold tracking-[-0.03em] text-money" /></dd>
            </div>
          </dl>
          <div class="grid grid-cols-2 gap-2">
            <UiButton variant="secondary" @click="borrowOpen = true"><ArrowDownLeft class="size-[18px]" :stroke-width="2.2" />{{ $t('debts.borrow') }}</UiButton>
            <UiButton variant="secondary" @click="lend()"><ArrowUpRight class="size-[18px]" :stroke-width="2.2" />{{ $t('debts.lend') }}</UiButton>
          </div>
          <p class="text-caption text-ink-3">{{ $t('debts.intro') }}</p>
        </section>

        <UiEmpty v-if="!debts.overview.owe.length && !debts.overview.owed.length" :icon="Handshake" :text="$t('debts.empty')" />

        <section v-if="debts.overview.owe.length" class="flex flex-col gap-2.5">
          <h2 class="section-title">{{ $t('debts.iOwe') }}</h2>
          <div class="group">
            <UiRow v-for="row in debts.overview.owe" :key="row.account_id" :title="row.name" :subtitle="oweLine(row)" :chevron="false" @click="openOwe(row)">
              <template #leading><AccountIcon type="debt" /></template>
              <template #trailing>
                <span class="flex flex-col items-end gap-0.5">
                  <MoneyAmount :amount="row.amount" :currency="row.currency" class="font-semibold" />
                  <span v-if="paidOff(row)" class="text-caption text-ink-3">{{ paidOff(row) }}</span>
                </span>
              </template>
            </UiRow>
          </div>
        </section>
      </div>

      <div class="page-col">
        <DebtPlan v-if="debts.overview.owe.length > 1" :debts="debts.overview.owe" :method="debts.overview.method" />

        <section v-if="debts.overview.owed.length" class="flex flex-col gap-2.5">
          <h2 class="section-title">{{ $t('debts.owedToMe') }}</h2>
          <div class="group">
            <UiRow v-for="row in debts.overview.owed" :key="row.account_id" :title="row.name" :chevron="false" @click="openOwed(row)">
              <template #leading><AccountIcon type="lent" /></template>
              <template #subtitle><span :class="{ 'font-medium text-danger': row.overdue }">{{ owedLine(row) }}</span></template>
              <template #trailing><MoneyAmount :amount="row.amount" :currency="row.currency" class="font-semibold text-money" /></template>
            </UiRow>
          </div>
        </section>
      </div>
    </div>

    <LendSheet v-model:open="lendOpen" :person="lendTo" />
    <BorrowSheet v-model:open="borrowOpen" />
    <SettleSheet v-model:open="settleOpen" :owe="owe" :owed="owed" @lend-more="lend" />
  </div>
</template>
