<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Minus, Plus } from 'lucide-vue-next'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { isoDate } from '@/lib/dates'
import { amountLabel } from '@/lib/habits'
import { haptics } from '@/lib/haptics'
import { dueLabel } from '@/lib/tasks'
import type { Habit } from '@/stores/habits'
import { useSettings } from '@/stores/settings'

/**
 * «Сколько сегодня»: the exact number for a count or limit habit, after a long press on its button.
 * From the calendar `date` can be another day: the title then names it, and `habit.today` holds that day.
 */
const props = defineProps<{ habit: Habit | null; date?: string }>()
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ save: [value: number] }>()

const value = ref(0)
watch(open, (isOpen) => {
  if (isOpen) value.value = props.habit?.today.value ?? 0
})

const { t } = useI18n()
const settings = useSettings()
/** «Вчера», «Пт, 23 октября», or nothing for today. */
const otherDay = computed(() => {
  const today = isoDate()
  return props.date && props.date !== today ? dueLabel({ due_on: props.date, due_time: null }, today, t, settings.locale) : null
})
const title = computed(() => {
  if (!props.habit) return t('habits.adjust')
  return otherDay.value ? `${props.habit.name} · ${otherDay.value}` : props.habit.name
})
const amount = computed(() => props.habit && amountLabel({ ...props.habit, today: { ...props.habit.today, value: value.value || 0 } }, t))
const over = computed(() => props.habit?.type === 'limit' && value.value > props.habit.target)

function step(delta: number): void {
  haptics.selection()
  value.value = Math.max(0, Math.min(100000, (value.value || 0) + delta))
}

function save(next = value.value): void {
  emit('save', Math.max(0, Math.round(next || 0)))
  open.value = false
}
</script>

<template>
  <UiSheet v-model:open="open" :title="title">
    <div v-if="habit" class="flex flex-col items-center gap-4 py-4">
      <span v-if="!otherDay" class="text-caption text-ink-3">{{ $t('habits.adjust') }}</span>
      <div class="flex items-center gap-5">
        <button type="button" class="press grid size-14 place-items-center rounded-full bg-surface-2" :aria-label="$t('habits.less')" @click="step(-1)">
          <Minus class="size-6" />
        </button>
        <input
          v-model.number="value"
          type="number"
          min="0"
          max="100000"
          inputmode="numeric"
          class="num w-28 bg-transparent text-center text-[3rem] leading-none font-semibold outline-none"
          :class="over ? 'text-danger' : 'text-ink'"
        />
        <button type="button" class="press grid size-14 place-items-center rounded-full bg-surface-2" :aria-label="$t('habits.more')" @click="step(1)">
          <Plus class="size-6" />
        </button>
      </div>
      <span class="num text-sm text-ink-3">{{ amount }}</span>
    </div>
    <template #footer>
      <div class="flex gap-2">
        <UiButton variant="secondary" @click="save(0)">{{ $t('habits.reset') }}</UiButton>
        <UiButton class="flex-1" @click="save()">{{ $t('habits.save') }}</UiButton>
      </div>
    </template>
  </UiSheet>
</template>
