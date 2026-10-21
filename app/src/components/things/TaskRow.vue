<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { NotebookText, Repeat2 } from 'lucide-vue-next'
import TaskCheck from '@/components/things/TaskCheck.vue'
import { dueLabel, repeatLabel } from '@/lib/tasks'
import { useSettings } from '@/stores/settings'
import type { Task } from '@/stores/tasks'

/** A task in a list: checkbox, title, and a line with the date, repeat and a note mark. */
const props = defineProps<{ task: Task; today: string; hideToday?: boolean }>()
const emit = defineEmits<{ toggle: []; open: [] }>()

const { t } = useI18n()
const settings = useSettings()

const done = computed(() => props.task.done_at !== null)
const due = computed(() => (props.hideToday && props.task.due_on === props.today ? props.task.due_time : dueLabel(props.task, props.today, t, settings.locale)))
const repeat = computed(() => (props.task.repeat ? repeatLabel(props.task.repeat, t) : null))
</script>

<template>
  <div class="flex items-start gap-3 pl-4">
    <span class="pt-3"><TaskCheck :checked="done" :priority="task.priority" :label="done ? $t('tasks.uncheck', { title: task.title }) : $t('tasks.check', { title: task.title })" @toggle="emit('toggle')" /></span>
    <button type="button" class="press-soft flex min-h-14 min-w-0 flex-1 flex-col justify-center gap-0.5 py-3 pr-4 text-left" @click="emit('open')">
      <span class="title break-words" :class="{ 'is-done': done }">{{ task.title }}</span>
      <span v-if="due || repeat || task.notes" class="flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-0.5 text-caption" :class="done ? 'text-ink-3' : 'text-ink-2'">
        <span v-if="due" :class="{ 'font-medium text-danger': task.overdue && !done }">{{ due }}</span>
        <span v-if="repeat" class="flex items-center gap-1"><Repeat2 class="size-3.5" :stroke-width="2.2" />{{ repeat }}</span>
        <NotebookText v-if="task.notes" class="size-3.5" :stroke-width="2.2" :aria-label="$t('tasks.hasNotes')" />
      </span>
    </button>
  </div>
</template>

<style scoped>
.title {
  text-decoration-line: line-through;
  text-decoration-thickness: 1.5px;
  text-decoration-color: transparent;
  transition:
    color var(--dur-base) var(--ease-standard),
    text-decoration-color var(--dur-base) var(--ease-standard);
}

.title.is-done {
  color: var(--c-ink-3);
  text-decoration-color: currentColor;
}
</style>
