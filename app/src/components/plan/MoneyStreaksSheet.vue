<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Flame } from 'lucide-vue-next'
import SpareDots from '@/components/things/SpareDots.vue'
import RollingDigits from '@/components/ui/RollingDigits.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { SPARE_RULE } from '@/lib/habits'
import type { MoneyStreak, MoneyStreaks } from '@/stores/plan'

/**
 * The money streaks one by one (docs/PLAN.md, D38): each as a counter with its record, the spare days
 * and what it counts, so «в лимите 12 дн.» on «Сегодня» is never a riddle.
 */
const props = defineProps<{ streaks: MoneyStreaks }>()
const open = defineModel<boolean>('open', { required: true })
const { t } = useI18n()

interface Row {
  key: string
  title: string
  hint: string
  streak: MoneyStreak
  status: string | null
}

const rows = computed<Row[]>(() => {
  const s = props.streaks
  const list: Row[] = []
  if (s.limit) {
    const status = s.limit.today === 'over' ? (s.limit.covered ? t('streaks.overCovered') : t('streaks.overBreaks')) : t('streaks.todayWithin')
    list.push({ key: 'limit', title: t('streaks.limitTitle'), hint: t('streaks.limitHint'), streak: s.limit, status })
  }
  list.push({
    key: 'records',
    title: t('streaks.recordsTitle'),
    hint: t('streaks.recordsHint'),
    streak: s.records,
    status: s.records.today === 'open' ? t('streaks.todayOpen') : null,
  })
  list.push({
    key: 'contributions',
    title: t('streaks.contributionsTitle'),
    hint: t('streaks.contributionsHint'),
    streak: s.contributions,
    status: s.contributions.today === 'kept' ? t('streaks.contributionsDone') : s.contributions.today === 'open' ? t('streaks.periodOpen') : null,
  })
  return list
})

function unitText(streak: MoneyStreak): string {
  return t(streak.unit === 'period' ? 'streaks.periodsInRow' : 'habits.daysInRow', streak.current)
}
</script>

<template>
  <UiSheet v-model:open="open" :title="$t('streaks.title')">
    <div class="flex flex-col divide-y divide-line">
      <section v-for="row in rows" :key="row.key" class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0">
        <h3 class="font-semibold">{{ row.title }}</h3>
        <div class="flex items-end gap-3">
          <span class="flex items-center gap-1 text-money">
            <Flame class="size-5 shrink-0" :stroke-width="2.2" aria-hidden="true" />
            <RollingDigits :text="String(row.streak.current)" class="text-[2rem] leading-none font-semibold tracking-[-0.03em]" />
          </span>
          <div class="flex min-w-0 flex-col pb-0.5">
            <span class="text-sm font-medium">{{ unitText(row.streak) }}</span>
            <span class="num text-caption text-ink-2">{{ $t('habits.record', { n: row.streak.best }) }}</span>
          </div>
        </div>
        <p v-if="row.streak.unit === 'day'" class="flex items-center gap-2 text-caption text-ink-2">
          <SpareDots :spare="row.streak.spare" :max="SPARE_RULE.day.max" color="var(--c-money)" />
          {{ row.streak.spare > 0 ? $t('habits.spareDays', { n: row.streak.spare }, row.streak.spare) : $t('habits.spareHintDays') }}
        </p>
        <p v-if="row.status" class="text-caption font-medium text-ink-2">{{ row.status }}</p>
        <p class="text-caption text-ink-3">{{ row.hint }}</p>
      </section>
    </div>
  </UiSheet>
</template>
