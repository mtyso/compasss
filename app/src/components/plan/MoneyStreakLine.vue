<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronRight, Flame } from 'lucide-vue-next'
import MoneyStreaksSheet from '@/components/plan/MoneyStreaksSheet.vue'
import UiButton from '@/components/ui/UiButton.vue'
import { showToast } from '@/components/ui/toast'
import { isoDate } from '@/lib/dates'
import { haptics } from '@/lib/haptics'
import { usePlan, type MoneyStreaks } from '@/stores/plan'

/**
 * The money streaks under the limit (docs/PLAN.md, D38): one quiet line in the colour of money,
 * «В лимите 12 дн. · записи 30 дн.», that opens them one by one. When today is already over the
 * limit it says what that does to the streak; in the evening, with nothing recorded, it offers
 * «Трат не было».
 */
const props = defineProps<{ streaks: MoneyStreaks }>()
const plan = usePlan()
const { t } = useI18n()

const parts = computed(() => {
  const list: string[] = []
  if (props.streaks.limit?.current) list.push(t('streaks.limit', { n: props.streaks.limit.current }))
  if (props.streaks.records.current) list.push(t('streaks.records', { n: props.streaks.records.current }))
  return list
})

const over = computed(() => {
  const limit = props.streaks.limit
  if (limit?.today !== 'over' || !limit.current) return null
  return limit.covered ? t('streaks.overCovered') : t('streaks.overBreaks')
})

// Before the evening an empty day is just a day that has not had its spending yet.
const EVENING_HOUR = 18
const offerNoSpending = computed(() => props.streaks.records.today === 'open' && new Date().getHours() >= EVENING_HOUR)

const sheetOpen = ref(false)
const busy = ref(false)

async function mark(value: boolean): Promise<void> {
  if (busy.value) return
  busy.value = true
  const date = isoDate()
  try {
    await plan.markNoSpending(date, value)
    if (value) {
      haptics.notify('success')
      showToast({ message: t('streaks.noSpendingDone'), tone: 'success', action: { label: t('tasks.undo'), run: () => mark(false) } })
    }
  } catch {
    haptics.notify('error')
    showToast({ message: t('streaks.failed'), tone: 'danger' })
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div v-if="parts.length || offerNoSpending" class="-mt-1 flex flex-col gap-1.5">
    <!-- On a phone «Трат не было» wraps under the line instead of cutting it short. -->
    <div class="flex min-h-9 flex-wrap items-center gap-x-3 gap-y-1.5">
      <button
        v-if="parts.length"
        type="button"
        class="press flex min-h-9 min-w-0 flex-auto items-center gap-1.5 text-left text-caption font-medium text-money"
        :aria-label="`${$t('streaks.open')}: ${parts.join(', ')}`"
        @click="sheetOpen = true"
      >
        <Flame class="size-3.5 shrink-0" :stroke-width="2.4" aria-hidden="true" />
        <span class="num">
          <template v-for="(part, i) in parts" :key="i"><span v-if="i" class="text-ink-3"> · </span>{{ part }}</template>
        </span>
        <ChevronRight class="size-3.5 shrink-0 text-ink-3" :stroke-width="2.4" aria-hidden="true" />
      </button>
      <span v-else class="flex-1" />
      <UiButton v-if="offerNoSpending" size="sm" variant="secondary" :loading="busy" @click="mark(true)">{{ $t('streaks.noSpending') }}</UiButton>
    </div>
    <p v-if="over" class="text-caption text-ink-2">{{ over }}</p>
    <MoneyStreaksSheet v-model:open="sheetOpen" :streaks="streaks" />
  </div>
</template>
