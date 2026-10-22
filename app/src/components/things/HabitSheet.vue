<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Archive, ArchiveRestore, Minus, Plus, Trash2 } from 'lucide-vue-next'
import AmountField from '@/components/AmountField.vue'
import GoalPicker from '@/components/goals/GoalPicker.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { showToast } from '@/components/ui/toast'
import { HABIT_ICONS } from '@/components/things/habitIcons'
import { ApiError } from '@/lib/api'
import { haptics } from '@/lib/haptics'
import { CATEGORY_COLORS } from '@/lib/palette'
import { weekdayNames } from '@/lib/tasks'
import { useHabits, type Habit, type HabitType } from '@/stores/habits'

/**
 * Create or edit a habit: name, type and target, schedule, icon and colour, the goal it works towards
 * and what a kept day saves; archive or delete. `draftGoalId` ties a new habit to a goal.
 */
const props = defineProps<{ habit: Habit | null; draftGoalId?: number | null }>()
const open = defineModel<boolean>('open', { required: true })

const habits = useHabits()
const { t } = useI18n()

const name = ref('')
const type = ref<HabitType>('check')
const target = ref(1)
const unit = ref('')
const scheduleMode = ref<'days' | 'per_week'>('days')
const days = ref<number[]>([1, 2, 3, 4, 5, 6, 7])
const perWeek = ref(3)
const icon = ref('target')
const color = ref('green')
const goalId = ref<number | null>(null)
const savesUzs = ref<number | null>(null)
const saving = ref(false)
const error = ref<string | null>(null)
const nameInput = ref<HTMLInputElement | null>(null)

const typeOptions = computed(() => (['check', 'count', 'limit'] as const).map((value) => ({ value, label: t(`habits.types.${value}`) })))
const scheduleOptions = computed(() => [
  { value: 'days' as const, label: t('habits.scheduleDays') },
  { value: 'per_week' as const, label: t('habits.schedulePerWeek') },
])
const weekdays = computed(() => weekdayNames(t))
const swatches = Object.keys(CATEGORY_COLORS)

watch(open, (value) => {
  if (!value) return
  const habit = props.habit
  error.value = null
  name.value = habit?.name ?? ''
  type.value = habit?.type ?? 'check'
  target.value = habit?.target ?? 1
  unit.value = habit?.unit ?? ''
  scheduleMode.value = habit && 'per_week' in habit.schedule ? 'per_week' : 'days'
  days.value = habit && 'days' in habit.schedule ? [...habit.schedule.days] : [1, 2, 3, 4, 5, 6, 7]
  perWeek.value = habit && 'per_week' in habit.schedule ? habit.schedule.per_week : 3
  icon.value = habit?.icon ?? 'target'
  color.value = habit?.color ?? 'green'
  goalId.value = habit ? habit.goal_id : (props.draftGoalId ?? null)
  savesUzs.value = habit?.saves_uzs ?? null
})

/** Switching the type: a limit counts per day, and count and limit start from a sensible target. */
function setType(next: HabitType): void {
  type.value = next
  if (next === 'limit') scheduleMode.value = 'days'
  if (next === 'count' && target.value < 2) target.value = 8
  if (next === 'limit' && target.value > 5) target.value = 2
}

function onOpened(): void {
  if (!props.habit) nextTick(() => nameInput.value?.focus({ preventScroll: true }))
}

function step(delta: number): void {
  haptics.selection()
  target.value = Math.max(type.value === 'limit' ? 0 : 1, Math.min(100000, (target.value || 0) + delta))
}

function toggleDay(day: number): void {
  haptics.selection()
  const next = days.value.includes(day) ? days.value.filter((d) => d !== day) : [...days.value, day].sort()
  if (next.length) days.value = next
}

const canSave = computed(() => name.value.trim() !== '' && (type.value !== 'count' || target.value >= 1))

async function save(): Promise<void> {
  if (!canSave.value || saving.value) return
  saving.value = true
  error.value = null
  try {
    await habits.save(
      {
        name: name.value.trim(),
        type: type.value,
        target: type.value === 'check' ? 1 : target.value,
        unit: type.value === 'check' ? null : unit.value.trim() || null,
        schedule: scheduleMode.value === 'days' ? { days: days.value } : { per_week: perWeek.value },
        icon: icon.value,
        color: color.value,
        goal_id: goalId.value,
        saves_uzs: savesUzs.value,
      },
      props.habit?.id,
    )
    haptics.notify('success')
    open.value = false
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('habits.failed')
  } finally {
    saving.value = false
  }
}

async function toggleArchive(): Promise<void> {
  if (!props.habit) return
  await habits.save({ archived: !props.habit.archived }, props.habit.id)
  open.value = false
}

async function remove(): Promise<void> {
  const habit = props.habit
  if (!habit) return
  open.value = false
  await habits.remove(habit.id)
  showToast({ message: t('habits.deleted'), detail: habit.name, action: { label: t('habits.undo'), run: () => habits.restore(habit.id) } })
}
</script>

<template>
  <UiSheet v-model:open="open" :title="habit ? habit.name : $t('habits.new')" @opened="onOpened">
    <div class="flex flex-col gap-5 pt-1">
      <label class="flex flex-col gap-2">
        <span class="field-label">{{ $t('habits.name') }}</span>
        <input ref="nameInput" v-model="name" maxlength="60" :placeholder="$t('habits.namePlaceholder')" class="field text-lg font-semibold" />
      </label>

      <section class="flex flex-col gap-2">
        <span class="field-label">{{ $t('habits.type') }}</span>
        <UiSegmented :model-value="type" :options="typeOptions" :label="$t('habits.type')" @update:model-value="setType" />
        <p class="px-1 text-caption text-ink-3">{{ $t(`habits.typeHints.${type}`) }}</p>
      </section>

      <Transition name="fade">
        <section v-if="type !== 'check'" class="flex flex-col gap-2">
          <span class="field-label">{{ type === 'limit' ? $t('habits.limit') : $t('habits.target') }}</span>
          <div class="flex items-center gap-2">
            <button type="button" class="press grid size-12 shrink-0 place-items-center rounded-control bg-surface-2" :aria-label="$t('habits.less')" @click="step(-1)">
              <Minus class="size-5" />
            </button>
            <input v-model.number="target" type="number" :min="type === 'limit' ? 0 : 1" max="100000" inputmode="numeric" class="field num w-24 text-center text-lg font-semibold" />
            <button type="button" class="press grid size-12 shrink-0 place-items-center rounded-control bg-surface-2" :aria-label="$t('habits.more')" @click="step(1)">
              <Plus class="size-5" />
            </button>
            <input v-model="unit" maxlength="20" :placeholder="$t('habits.unitPlaceholder')" :aria-label="$t('habits.unit')" class="field min-w-0 flex-1" />
          </div>
        </section>
      </Transition>

      <section class="flex flex-col gap-2">
        <span class="field-label">{{ $t('habits.scheduleTitle') }}</span>
        <UiSegmented v-if="type !== 'limit'" v-model="scheduleMode" :options="scheduleOptions" :label="$t('habits.scheduleTitle')" />
        <div v-if="scheduleMode === 'days'" class="grid grid-cols-7 gap-1">
          <button
            v-for="(day, index) in weekdays"
            :key="day"
            type="button"
            class="press rounded-control py-2.5 text-sm font-medium"
            :class="days.includes(index + 1) ? 'bg-ink text-surface' : 'bg-surface-2 text-ink-2'"
            :aria-pressed="days.includes(index + 1)"
            @click="toggleDay(index + 1)"
          >
            {{ day }}
          </button>
        </div>
        <div v-else class="grid grid-cols-7 gap-1">
          <button
            v-for="n in 7"
            :key="n"
            type="button"
            class="press num rounded-control py-2.5 text-sm font-semibold"
            :class="perWeek === n ? 'bg-ink text-surface' : 'bg-surface-2 text-ink-2'"
            :aria-pressed="perWeek === n"
            @click="haptics.selection(); perWeek = n"
          >
            {{ n }}
          </button>
        </div>
        <p class="px-1 text-caption text-ink-3">
          {{ scheduleMode === 'per_week' ? $t('habits.schedule.perWeek', perWeek) : '' }}
        </p>
      </section>

      <section class="flex flex-col gap-2">
        <span class="field-label">{{ $t('habits.icon') }}</span>
        <div class="grid grid-cols-8 gap-1.5">
          <button
            v-for="(component, key) in HABIT_ICONS"
            :key="key"
            type="button"
            class="press grid aspect-square place-items-center rounded-control"
            :class="icon === key ? 'text-surface' : 'bg-surface-2 text-ink-2'"
            :style="icon === key ? { backgroundColor: CATEGORY_COLORS[color] } : undefined"
            :aria-label="String(key)"
            :aria-pressed="icon === key"
            @click="haptics.selection(); icon = String(key)"
          >
            <component :is="component" class="size-5" :stroke-width="1.9" />
          </button>
        </div>
      </section>

      <section class="flex flex-col gap-2">
        <span class="field-label">{{ $t('habits.color') }}</span>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="key in swatches"
            :key="key"
            type="button"
            class="swatch press size-9 rounded-full"
            :class="{ on: color === key }"
            :style="{ backgroundColor: CATEGORY_COLORS[key] }"
            :aria-label="key"
            :aria-pressed="color === key"
            @click="haptics.selection(); color = key"
          />
        </div>
      </section>

      <GoalPicker v-model="goalId" />

      <div class="flex flex-col gap-2">
        <AmountField v-model="savesUzs" currency="UZS" :label="$t('habits.savesUzs')" />
        <p class="text-caption text-ink-3">{{ $t('habits.savesHint') }}</p>
      </div>

      <div v-if="habit" class="flex flex-col items-center gap-1">
        <UiButton variant="ghost" size="sm" @click="toggleArchive">
          <component :is="habit.archived ? ArchiveRestore : Archive" class="size-4" />
          {{ habit.archived ? $t('habits.unarchive') : $t('habits.archive') }}
        </UiButton>
        <UiButton variant="ghost" size="sm" class="!text-danger" @click="remove"><Trash2 class="size-4" />{{ $t('habits.delete') }}</UiButton>
      </div>
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <UiButton block :disabled="!canSave" :loading="saving" @click="save">{{ $t('habits.save') }}</UiButton>
    </template>
  </UiSheet>
</template>

<style scoped>
.swatch {
  box-shadow: 0 0 0 0 var(--c-surface);
  transition:
    transform var(--dur-spring) var(--ease-spring),
    box-shadow var(--dur-fast) var(--ease-standard);
}

.swatch.on {
  box-shadow:
    0 0 0 2px var(--c-bg),
    0 0 0 4px var(--c-ink);
  transform: scale(0.92);
}
</style>
