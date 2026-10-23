<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import { isoDate } from '@/lib/dates'
import { haptics } from '@/lib/haptics'
import { isoWeekday, weekdayNames } from '@/lib/tasks'
import type { RepeatInput, RepeatUnit } from '@/stores/tasks'

/**
 * «Повтор» of a task or an event: Нет, the four simple rules, or Другое with every N days, weeks,
 * months or years and the weekdays of a weekly one. `startDate` is the date the rule repeats from
 * (today when there is none yet); a custom weekly rule starts on its weekday.
 */
const props = defineProps<{ startDate?: string | null }>()
const model = defineModel<RepeatInput | null>({ required: true })

const { t } = useI18n()

type RepeatChoice = 'none' | RepeatUnit | 'custom'

const choice = ref<RepeatChoice>('none')
const every = ref(2)
const unit = ref<RepeatUnit>('day')
const days = ref<number[]>([])

function fill(rule: RepeatInput | null): void {
  const simple = rule && rule.every === 1 && !rule.days?.length
  choice.value = !rule ? 'none' : simple ? rule.unit : 'custom'
  every.value = rule && !simple ? rule.every : 2
  unit.value = rule?.unit ?? 'day'
  days.value = rule?.days ? [...rule.days] : []
}
fill(model.value)

const unitOptions = computed(() => (['day', 'week', 'month', 'year'] as const).map((value) => ({ value, label: t(`tasks.units.${value}`) })))
const weekdays = computed(() => weekdayNames(t))
const choices = computed<{ value: RepeatChoice; label: string }[]>(() => [
  { value: 'none', label: t('tasks.repeatNone') },
  { value: 'day', label: t('things.repeat.day') },
  { value: 'week', label: t('things.repeat.week') },
  { value: 'month', label: t('things.repeat.month') },
  { value: 'year', label: t('things.repeat.year') },
  { value: 'custom', label: t('tasks.repeatCustom') },
])

const rule = computed<RepeatInput | null>(() => {
  if (choice.value === 'none') return null
  if (choice.value !== 'custom') return { unit: choice.value, every: 1 }
  return { unit: unit.value, every: Math.max(1, every.value || 1), ...(unit.value === 'week' && days.value.length ? { days: days.value } : {}) }
})

// The chips write the rule; a rule set from outside (the sheet opening, the date taken away) fills the chips.
let written = JSON.stringify(model.value)
watch(rule, (value) => {
  written = JSON.stringify(value)
  model.value = value
})
watch(model, (value) => {
  if (JSON.stringify(value) === written) return
  written = JSON.stringify(value)
  // «Нет» from outside keeps what «Другое» had, as before the picker was split out.
  if (!value) choice.value = 'none'
  else fill(value)
})

function pick(next: RepeatChoice): void {
  haptics.selection()
  choice.value = next
  if (next === 'custom' && unit.value === 'week' && !days.value.length) days.value = [isoWeekday(props.startDate || isoDate())]
}

function toggleDay(day: number): void {
  haptics.selection()
  days.value = days.value.includes(day) ? days.value.filter((d) => d !== day) : [...days.value, day].sort()
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <div class="flex flex-wrap gap-2">
      <button
        v-for="option in choices"
        :key="option.value"
        type="button"
        class="chip"
        :class="{ 'chip-on': choice === option.value }"
        :aria-pressed="choice === option.value"
        @click="pick(option.value)"
      >
        {{ option.label }}
      </button>
    </div>
    <Transition name="fade">
      <div v-if="choice === 'custom'" class="flex flex-col gap-3">
        <div class="flex items-center gap-2">
          <span class="text-sm text-ink-2">{{ $t('tasks.every') }}</span>
          <input v-model.number="every" type="number" min="1" max="365" inputmode="numeric" class="field num w-20 !py-2 text-center font-semibold" />
        </div>
        <UiSegmented v-model="unit" size="sm" :options="unitOptions" :label="$t('tasks.repeat')" />
        <div v-if="unit === 'week'" class="grid grid-cols-7 gap-1">
          <button
            v-for="(name, index) in weekdays"
            :key="name"
            type="button"
            class="press rounded-control py-2 text-sm font-medium"
            :class="days.includes(index + 1) ? 'bg-ink text-surface' : 'bg-surface-2 text-ink-2'"
            :aria-pressed="days.includes(index + 1)"
            @click="toggleDay(index + 1)"
          >
            {{ name }}
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>
