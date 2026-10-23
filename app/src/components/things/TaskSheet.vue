<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Trash2, X } from 'lucide-vue-next'
import GoalPicker from '@/components/goals/GoalPicker.vue'
import RepeatPicker from '@/components/things/RepeatPicker.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { showToast } from '@/components/ui/toast'
import { ApiError } from '@/lib/api'
import { isoDate, shiftDate } from '@/lib/dates'
import { haptics } from '@/lib/haptics'
import { useTasks, type Priority, type RepeatInput, type Task, type TaskInput } from '@/stores/tasks'

/**
 * Create or edit a task: title, note, date and time, priority and repeat. `task` edits an existing
 * one; `draft` pre-fills a new one (a task added from «Сегодня» is due today).
 */
const props = defineProps<{ task: Task | null; draft?: Partial<TaskInput> }>()
const open = defineModel<boolean>('open', { required: true })

const tasks = useTasks()
const { t } = useI18n()

const title = ref('')
const notes = ref('')
const dueOn = ref<string | null>(null)
const dueTime = ref<string | null>(null)
const priority = ref<Priority>(0)
const goalId = ref<number | null>(null)
const repeat = ref<RepeatInput | null>(null)
const saving = ref(false)
const error = ref<string | null>(null)
const titleInput = ref<HTMLInputElement | null>(null)

const today = computed(() => isoDate())
const tomorrow = computed(() => shiftDate(today.value, 1))
const priorityOptions = computed(() => ([0, 1, 2] as const).map((value) => ({ value, label: t(`tasks.priorities.p${value}`) })))

watch(open, (value) => {
  if (!value) return
  const task = props.task
  const draft = props.draft ?? {}
  error.value = null
  title.value = task?.title ?? draft.title ?? ''
  notes.value = task?.notes ?? draft.notes ?? ''
  dueOn.value = task ? task.due_on : (draft.due_on ?? null)
  dueTime.value = task?.due_time ?? draft.due_time ?? null
  priority.value = task?.priority ?? draft.priority ?? 0
  goalId.value = task ? task.goal_id : (draft.goal_id ?? null)
  const rule = task?.repeat ?? null
  repeat.value = rule && { unit: rule.unit, every: rule.every, ...(rule.days?.length ? { days: [...rule.days] } : {}) }
})

function onOpened(): void {
  if (!props.task) nextTick(() => titleInput.value?.focus({ preventScroll: true }))
}

// A repeat needs a date to repeat from.
watch(repeat, (rule) => {
  if (rule && !dueOn.value) dueOn.value = today.value
})

function pickDate(value: string | null): void {
  haptics.selection()
  dueOn.value = value
  if (!value) {
    dueTime.value = null
    repeat.value = null
  }
}

const canSave = computed(() => title.value.trim() !== '')

async function save(): Promise<void> {
  if (!canSave.value || saving.value) return
  saving.value = true
  error.value = null
  try {
    await tasks.save(
      { title: title.value.trim(), notes: notes.value.trim() || null, due_on: dueOn.value, due_time: dueOn.value ? dueTime.value || null : null, priority: priority.value, repeat: repeat.value, goal_id: goalId.value },
      props.task?.id,
    )
    haptics.notify('success')
    open.value = false
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('tasks.failed')
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  const task = props.task
  if (!task) return
  open.value = false
  await tasks.remove(task.id)
  showToast({ message: t('tasks.deleted'), detail: task.title, action: { label: t('tasks.undo'), run: () => tasks.restore(task.id) } })
}
</script>

<template>
  <UiSheet v-model:open="open" :title="task ? $t('tasks.edit') : $t('tasks.new')" @opened="onOpened">
    <div class="flex flex-col gap-5 pt-1">
      <div class="flex flex-col gap-2">
        <input
          ref="titleInput"
          v-model="title"
          maxlength="200"
          enterkeyhint="done"
          :placeholder="$t('tasks.titlePlaceholder')"
          class="field text-lg font-semibold"
          @keydown.enter.prevent="save"
        />
        <textarea v-model="notes" rows="2" maxlength="5000" :placeholder="$t('tasks.notesPlaceholder')" class="field resize-none text-body" />
      </div>

      <section class="flex flex-col gap-2">
        <h3 class="field-label">{{ $t('tasks.date') }}</h3>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="chip" :class="{ 'chip-on': dueOn === today }" :aria-pressed="dueOn === today" @click="pickDate(today)">{{ $t('things.today') }}</button>
          <button type="button" class="chip" :class="{ 'chip-on': dueOn === tomorrow }" :aria-pressed="dueOn === tomorrow" @click="pickDate(tomorrow)">{{ $t('things.tomorrow') }}</button>
          <input
            type="date"
            :value="dueOn ?? ''"
            :min="task ? undefined : today"
            class="chip"
            :class="{ 'chip-on': dueOn && dueOn !== today && dueOn !== tomorrow }"
            @change="pickDate(($event.target as HTMLInputElement).value || null)"
          />
          <button type="button" class="chip" :class="{ 'chip-on': !dueOn }" :aria-pressed="!dueOn" @click="pickDate(null)">{{ $t('tasks.noDate') }}</button>
        </div>
        <Transition name="fade">
          <div v-if="dueOn" class="flex items-center gap-2">
            <label class="chip" :class="{ 'chip-on': dueTime }">
              <span class="sr-only">{{ $t('tasks.time') }}</span>
              <input v-model="dueTime" type="time" class="bg-transparent outline-none" :aria-label="$t('tasks.time')" />
            </label>
            <button v-if="dueTime" type="button" class="press grid size-9 place-items-center rounded-full text-ink-3" :aria-label="$t('tasks.clearTime')" @click="dueTime = null">
              <X class="size-4" />
            </button>
          </div>
        </Transition>
      </section>

      <section class="flex flex-col gap-2">
        <h3 class="field-label">{{ $t('tasks.priority') }}</h3>
        <UiSegmented v-model="priority" :options="priorityOptions" :label="$t('tasks.priority')" />
      </section>

      <section class="flex flex-col gap-2">
        <h3 class="field-label">{{ $t('tasks.repeat') }}</h3>
        <RepeatPicker v-model="repeat" :start-date="dueOn" />
      </section>

      <GoalPicker v-model="goalId" />
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <div class="flex gap-2">
        <UiButton v-if="task" variant="danger-soft" class="!w-12 !px-0" :aria-label="$t('tasks.delete')" @click="remove">
          <Trash2 class="size-5" />
        </UiButton>
        <UiButton class="flex-1" :disabled="!canSave" :loading="saving" @click="save">{{ $t('tasks.save') }}</UiButton>
      </div>
    </template>
  </UiSheet>
</template>
