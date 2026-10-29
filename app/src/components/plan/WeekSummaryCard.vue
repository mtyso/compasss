<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDownRight, ArrowUpRight, ChevronRight } from 'lucide-vue-next'
import { recordLook } from '@/components/plan/recordLook'
import { formatDayMonth, formatMoney } from '@/lib/format'
import { useSettings } from '@/stores/settings'
import type { WeekNumbers, WeekSummary } from '@/stores/plan'

/**
 * «Итог недели» on a Monday (docs/PLAN.md, D39): days in the limit, habits done, money put into goals,
 * each beside the week before, and the week's records. Plain numbers, no score: a worse week gets a quiet
 * arrow, never red.
 */
const props = defineProps<{ week: WeekSummary }>()
const settings = useSettings()
const { t } = useI18n()

const range = computed(() => {
  const { from, to } = props.week
  return from.slice(0, 7) === to.slice(0, 7)
    ? `${Number(from.slice(8, 10))}–${formatDayMonth(to, settings.locale)}`
    : `${formatDayMonth(from, settings.locale)} – ${formatDayMonth(to, settings.locale)}`
})

interface Row {
  key: string
  label: string
  value: string
  before: string | null
  trend: 'up' | 'down' | null
}

const share = (n: { done: number; asked: number } | null) => (n && n.asked ? n.done / n.asked : null)
const limitShare = (n: WeekNumbers['limit']) => (n && n.days ? n.kept / n.days : null)
const percent = (value: number) => `${Math.round(value * 100)}%`

function trend(now: number | null, before: number | null): 'up' | 'down' | null {
  if (now === null || before === null || Math.abs(now - before) < 0.005) return null
  return now > before ? 'up' : 'down'
}

const rows = computed<Row[]>(() => {
  const { week } = props
  const prev = week.prev
  const list: Row[] = []
  if (week.limit) {
    list.push({
      key: 'limit',
      label: t('week.limit'),
      value: t('week.of', week.limit),
      before: prev.limit ? t('week.before', { value: t('week.of', prev.limit) }) : null,
      trend: trend(limitShare(week.limit), limitShare(prev.limit)),
    })
  }
  const habits = share(week.habits)
  if (habits !== null) {
    const before = share(prev.habits)
    list.push({
      key: 'habits',
      label: t('week.habits'),
      value: percent(habits),
      before: before === null ? null : t('week.before', { value: percent(before) }),
      trend: trend(habits, before),
    })
  }
  if (week.goals_uzs || prev.goals_uzs) {
    list.push({
      key: 'goals',
      label: t('week.goals'),
      value: formatMoney(week.goals_uzs, 'UZS', settings.locale),
      before: t('week.before', { value: formatMoney(prev.goals_uzs, 'UZS', settings.locale) }),
      trend: trend(week.goals_uzs, prev.goals_uzs),
    })
  }
  return list
})

const records = computed(() => props.week.records.slice(0, 3))
</script>

<template>
  <section v-if="rows.length || records.length" class="card flex flex-col gap-3 p-4" data-panel>
    <div class="flex items-baseline justify-between gap-3">
      <h2 class="text-headline font-semibold">{{ $t('week.title') }}</h2>
      <span class="num text-caption text-ink-3">{{ range }}</span>
    </div>

    <dl v-if="rows.length" class="flex flex-col">
      <div v-for="row in rows" :key="row.key" class="flex items-start gap-3 border-t border-line py-2.5 first:border-t-0 first:pt-0">
        <dt class="flex-1 pt-0.5 text-ink-2">{{ row.label }}</dt>
        <dd class="flex flex-col items-end gap-0.5 text-right">
          <span class="num flex items-center gap-1 font-semibold">
            <ArrowUpRight v-if="row.trend === 'up'" class="size-4 text-money" :stroke-width="2.4" :aria-label="$t('week.up')" />
            <ArrowDownRight v-else-if="row.trend === 'down'" class="size-4 text-ink-3" :stroke-width="2.4" :aria-label="$t('week.down')" />
            {{ row.value }}
          </span>
          <span v-if="row.before" class="num text-caption text-ink-3">{{ row.before }}</span>
        </dd>
      </div>
    </dl>

    <p v-if="week.main" class="text-caption font-medium text-money">{{ $t('week.closer', { goal: week.main.title, n: week.main.days }) }}</p>

    <div v-if="records.length" class="flex flex-col gap-2 border-t border-line pt-3">
      <span class="text-caption font-medium text-ink-2">{{ $t('week.records') }}</span>
      <p v-for="record in records" :key="record.id" class="flex items-center gap-2 text-sm">
        <component :is="recordLook(record).icon" class="size-4 shrink-0" :class="recordLook(record).text" :stroke-width="2.2" aria-hidden="true" />
        <span class="min-w-0 flex-1">{{ record.text }}</span>
      </p>
    </div>

    <RouterLink :to="{ name: 'records' }" class="press flex items-center gap-1 self-start text-caption font-medium text-money">
      {{ $t('week.allRecords') }}
      <ChevronRight class="size-3.5" :stroke-width="2.4" aria-hidden="true" />
    </RouterLink>
  </section>
</template>
