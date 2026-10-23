<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import { monthGrid } from '@/lib/calendar'
import { shiftMonth } from '@/lib/dates'
import { formatDayMonth, formatMonthTitle } from '@/lib/format'
import { weekdayNames, weekdayShort } from '@/lib/tasks'
import { useSettings } from '@/stores/settings'

/**
 * A small month to jump to any date: today ringed, the chosen day filled, days that have something
 * dotted. ‹ › page the months without changing the choice. `compact` is the side panel's size.
 */
const props = withDefaults(defineProps<{ selected: string; today: string; marked?: ReadonlySet<string>; compact?: boolean }>(), {
  marked: () => new Set<string>(),
  compact: false,
})
const month = defineModel<string>('month', { required: true })
const emit = defineEmits<{ pick: [day: string] }>()

const { t } = useI18n()
const settings = useSettings()

const days = computed(() => monthGrid(month.value))
const title = computed(() => formatMonthTitle(month.value, settings.locale))

function look(day: string): string {
  const selected = day === props.selected
  const today = day === props.today
  if (selected && today) return 'bg-money text-on-money font-semibold'
  if (selected) return 'bg-ink text-bg font-semibold'
  if (today) return 'text-money font-semibold shadow-[inset_0_0_0_1.5px_var(--c-money)]'
  return day.slice(0, 7) === month.value ? 'text-ink hover:bg-ink/5' : 'text-ink-3 hover:bg-ink/5'
}
</script>

<template>
  <div class="flex flex-col gap-1">
    <div class="flex items-center gap-1" :class="compact ? 'pl-1.5' : 'pl-1'">
      <span class="min-w-0 flex-1 truncate font-semibold" :class="compact ? 'text-sm' : 'text-section'" aria-live="polite">{{ title }}</span>
      <UiIconButton size="sm" :label="t('calendar.prev')" @click="month = shiftMonth(month, -1)">
        <ChevronLeft class="size-[18px]" :stroke-width="2.2" />
      </UiIconButton>
      <UiIconButton size="sm" :label="t('calendar.next')" @click="month = shiftMonth(month, 1)">
        <ChevronRight class="size-[18px]" :stroke-width="2.2" />
      </UiIconButton>
    </div>

    <div class="grid grid-cols-7" aria-hidden="true">
      <span v-for="name in weekdayNames(t)" :key="name" class="py-1 text-center text-micro font-medium text-ink-3">{{ name }}</span>
    </div>
    <div class="grid grid-cols-7 gap-y-0.5">
      <span v-for="day in days" :key="day" class="grid place-items-center">
        <button
          type="button"
          class="press num relative grid place-items-center rounded-full"
          :class="[compact ? 'size-8 text-caption' : 'size-10 text-sm', look(day)]"
          :aria-label="`${weekdayShort(day, t)}, ${formatDayMonth(day, settings.locale)}`"
          :aria-pressed="day === selected"
          :aria-current="day === today ? 'date' : undefined"
          @click="emit('pick', day)"
        >
          {{ Number(day.slice(8, 10)) }}
          <span
            v-if="marked.has(day) && day !== selected"
            class="absolute left-1/2 size-1 -translate-x-1/2 rounded-full bg-current opacity-60"
            :class="compact ? 'bottom-0.5' : 'bottom-1'"
            aria-hidden="true"
          />
        </button>
      </span>
    </div>
  </div>
</template>
