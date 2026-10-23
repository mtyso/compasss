<script setup lang="ts">
import { computed, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check } from 'lucide-vue-next'
import HabitButton from '@/components/things/HabitButton.vue'
import TaskCheck from '@/components/things/TaskCheck.vue'
import { habitIcon } from '@/components/things/habitIcons'
import { eventFill } from '@/lib/calendar'
import { softHyphenate } from '@/lib/hyphenate'
import type { EventItem, HabitItem, TaskItem } from '@/stores/calendar'
import type { Habit } from '@/stores/habits'

/**
 * A timed thing on the day or week timeline: an event as a solid block in its colour, a task as a
 * soft blue one, a habit tied to a routine block (when the routine itself is hidden) as a soft amber
 * one. The whole block is one tap target; `controls` adds the task's check or the habit's button
 * inside, for blocks big enough to hold them. The parent places it (top, height, left, width) and
 * moves it while it is dragged.
 */
const props = withDefaults(
  defineProps<{
    item: EventItem | TaskItem | HabitItem
    /** Its height in px, which decides what fits inside. */
    height: number
    /** The narrow columns of a phone's week: 11px text, words hyphenated. */
    compact?: boolean
    controls?: boolean
    /** The habit as it stood that day (habit items with controls). */
    habit?: Habit | null
    /** It goes on from the day before / into the next day: that edge is drawn flat. */
    flatTop?: boolean
    flatBottom?: boolean
    /** An event a mouse can drag and stretch. */
    draggable?: boolean
    /** It is being dragged. */
    lifted?: boolean
    /** The times while it is dragged. */
    timeText?: string | null
  }>(),
  { compact: false, controls: false, habit: null, flatTop: false, flatBottom: false, draggable: false, lifted: false, timeText: null },
)
const emit = defineEmits<{
  open: []
  toggle: []
  habitTap: []
  habitHold: []
  grab: [event: PointerEvent, mode: 'move' | 'resize']
}>()

const { t } = useI18n()

const lineHeight = computed(() => (props.compact ? 13 : 17))
const showTime = computed(() => props.height >= 40)
/** As many title lines as the block has room for, so a line is never cut in half. */
const titleLines = computed(() => Math.max(1, Math.floor((props.height - 6 - (showTime.value ? lineHeight.value : 0)) / lineHeight.value)))

const time = computed(() => {
  if (props.timeText) return props.timeText
  const item = props.item
  if (!item.start) return ''
  if (item.type !== 'event' || props.compact || !item.end) return item.start
  return `${item.start}–${item.end}`
})

// A phone's week column holds about six letters a line: «Встре-ча», not «Встре» and «ча».
const title = computed(() => (props.compact ? softHyphenate(props.item.title, 6, 2) : props.item.title))
const label = computed(() => [props.item.title, time.value].filter(Boolean).join(', '))

const look = computed(() => {
  const item = props.item
  if (item.type === 'event') return 'text-white'
  if (item.type === 'habit') return 'bg-habit-soft text-habit'
  if (item.done) return 'bg-task-soft text-ink-3'
  return item.projected ? 'bg-task-soft text-ink-2' : 'bg-task-soft text-task'
})

const future = computed(() => props.item.type === 'habit' && props.item.state === 'future')
const showTaskCheck = computed(() => props.controls && props.item.type === 'task' && !props.item.projected)
const showHabitButton = computed(() => props.controls && props.item.type === 'habit' && !future.value && !!props.habit)

/* ---------- Tap, hold (habits) and the mouse drag (events) ---------- */

let holdTimer: number | undefined
let held = false

function down(event: PointerEvent): void {
  if (props.draggable) emit('grab', event, 'move')
  if (props.item.type !== 'habit' || future.value) return
  held = false
  holdTimer = window.setTimeout(() => {
    held = true
    emit('habitHold')
  }, 480)
}

function cancelHold(): void {
  window.clearTimeout(holdTimer)
}

function tap(): void {
  if (held) return
  if (props.item.type === 'habit') {
    if (!future.value) emit('habitTap')
    return
  }
  emit('open')
}

onBeforeUnmount(cancelHold)
</script>

<template>
  <div
    class="thing absolute"
    :class="[
      look,
      // The task's check reaches past a short block to keep a 44px target; everything else stays inside.
      { 'overflow-hidden': !showTaskCheck },
      flatTop ? 'rounded-t-none' : 'rounded-t-[0.5rem]',
      flatBottom ? 'rounded-b-none' : 'rounded-b-[0.5rem]',
      { 'is-event': item.type === 'event' },
      { 'is-lifted': lifted },
    ]"
    :style="item.type === 'event' ? { backgroundColor: eventFill(item.color) } : undefined"
    data-block
  >
    <button
      type="button"
      class="absolute inset-0"
      :class="draggable ? 'cursor-pointer' : future ? 'cursor-default' : ''"
      :aria-label="label"
      @pointerdown="down"
      @pointerup="cancelHold"
      @pointerleave="cancelHold"
      @pointercancel="cancelHold"
      @contextmenu.prevent
      @click="tap"
    />
    <div
      class="pointer-events-none relative flex h-full min-w-0 flex-col"
      :class="[compact ? 'px-1 py-0.5 text-micro font-semibold' : 'px-1.5 py-[3px] text-caption', { 'pr-10': showTaskCheck || showHabitButton }]"
    >
      <span class="flex min-w-0 items-start gap-1" :style="{ lineHeight: `${lineHeight}px` }">
        <template v-if="!compact">
          <template v-if="item.type === 'task' && !item.projected">
            <Check v-if="item.done" class="mt-[3px] size-3 shrink-0" :stroke-width="2.6" aria-hidden="true" />
            <span v-else-if="!showTaskCheck" class="mt-[4px] size-2.5 shrink-0 rounded-full border-[1.5px] border-current" aria-hidden="true" />
          </template>
          <component :is="habitIcon(item.icon)" v-else-if="item.type === 'habit'" class="mt-[2px] size-3.5 shrink-0" :stroke-width="2.2" aria-hidden="true" />
        </template>
        <span
          class="title min-w-0 break-words"
          :class="[item.type === 'event' ? 'font-semibold' : 'font-medium', { 'line-through': item.type === 'task' && item.done, 'hyphens-manual': compact }]"
          :style="{ WebkitLineClamp: titleLines }"
        >
          {{ title }}
        </span>
      </span>
      <span v-if="showTime && time" class="num truncate opacity-85" :class="compact ? 'text-micro font-medium' : 'text-micro'" :style="{ lineHeight: `${lineHeight}px` }">
        {{ time }}
      </span>
    </div>

    <span
      v-if="showTaskCheck && item.type === 'task'"
      class="absolute right-1.5 z-[1] grid size-8 place-items-center"
      :class="height < 36 ? 'top-1/2 -translate-y-1/2' : 'top-0.5'"
    >
      <TaskCheck
        :checked="item.done"
        :priority="item.priority"
        :label="item.done ? t('tasks.uncheck', { title: item.title }) : t('tasks.check', { title: item.title })"
        @toggle="emit('toggle')"
      />
    </span>
    <span v-else-if="showHabitButton && habit" class="absolute top-1 right-1 z-[1]">
      <HabitButton
        :habit="habit"
        size="sm"
        :label="habit.type === 'check' ? t(habit.today.value > 0 ? 'habits.unmark' : 'habits.mark', { name: habit.name }) : t('habits.addOne', { name: habit.name })"
        @tap="emit('habitTap')"
        @hold="emit('habitHold')"
      />
    </span>

    <span
      v-if="draggable && !flatBottom"
      class="absolute inset-x-0 bottom-0 z-[1] h-1.5 cursor-ns-resize"
      aria-hidden="true"
      @pointerdown.stop="emit('grab', $event, 'resize')"
    />
  </div>
</template>

<style scoped>
.title {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* An event is the one solid thing on the day: lit from above and lifted a hair off the routine under it. */
.is-event {
  background-image: linear-gradient(180deg, rgb(255 255 255 / 0.12), rgb(255 255 255 / 0) 24px);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / 0.16),
    0 1px 2px rgb(0 0 0 / 0.12),
    0 0 0 1px var(--c-bg);
}

.is-lifted {
  z-index: 3;
  box-shadow: var(--c-shadow-raised);
  opacity: 0.94;
}
</style>
