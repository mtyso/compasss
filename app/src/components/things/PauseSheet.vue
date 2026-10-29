<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { showToast } from '@/components/ui/toast'
import { ApiError } from '@/lib/api'
import { isoDate, shiftDate } from '@/lib/dates'
import { haptics } from '@/lib/haptics'
import { useHabits, type HabitPause } from '@/stores/habits'

/**
 * A pause for all habits (docs/PLAN.md, D37): a trip or an illness, from one day to another. Streaks
 * wait it out. It can start up to two weeks back, for an illness noticed late; a pause ahead can be
 * moved or cancelled here.
 */
const props = defineProps<{ pause: HabitPause | null }>()
const open = defineModel<boolean>('open', { required: true })

const habits = useHabits()
const { t } = useI18n()

const from = ref(isoDate())
const to = ref(shiftDate(isoDate(), 6))
const busy = ref<'save' | 'cancel' | null>(null)
const error = ref<string | null>(null)

watch(open, (value) => {
  if (!value) return
  error.value = null
  from.value = props.pause?.starts_on ?? isoDate()
  to.value = props.pause?.ends_on ?? shiftDate(isoDate(), 6)
})

const earliest = computed(() => shiftDate(isoDate(), -14))
const length = computed(() => {
  const days = Math.round((Date.parse(`${to.value}T12:00:00Z`) - Date.parse(`${from.value}T12:00:00Z`)) / 86_400_000) + 1
  return Number.isFinite(days) && days > 0 ? days : 0
})

async function save(): Promise<void> {
  if (busy.value || !length.value) return
  busy.value = 'save'
  error.value = null
  try {
    await habits.savePause({ starts_on: from.value, ends_on: to.value }, props.pause?.id)
    haptics.notify('success')
    showToast({ message: t('habits.pause.saved'), tone: 'success' })
    open.value = false
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('habits.pause.failed')
  } finally {
    busy.value = null
  }
}

async function cancel(): Promise<void> {
  if (!props.pause || busy.value) return
  busy.value = 'cancel'
  try {
    await habits.removePause(props.pause.id)
    open.value = false
  } catch {
    error.value = t('habits.pause.failed')
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <UiSheet v-model:open="open" :title="$t('habits.pause.title')">
    <div class="flex flex-col gap-5">
      <p class="text-[0.9375rem] text-ink-2">{{ $t('habits.pause.hint') }}</p>
      <div class="flex gap-3">
        <label class="flex min-w-0 flex-1 flex-col gap-2">
          <span class="field-label">{{ $t('habits.pause.from') }}</span>
          <input v-model="from" type="date" :min="earliest" class="field" />
        </label>
        <label class="flex min-w-0 flex-1 flex-col gap-2">
          <span class="field-label">{{ $t('habits.pause.to') }}</span>
          <input v-model="to" type="date" :min="from" class="field" />
        </label>
      </div>
      <p v-if="length" class="num text-caption text-ink-3">{{ $t('habits.pause.length', { n: length }, length) }}</p>
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <div class="flex flex-col gap-2">
        <UiButton block :disabled="!length" :loading="busy === 'save'" @click="save">{{ $t('habits.pause.save') }}</UiButton>
        <UiButton v-if="pause" variant="ghost" block :loading="busy === 'cancel'" @click="cancel">{{ $t('habits.pause.cancel') }}</UiButton>
      </div>
    </template>
  </UiSheet>
</template>
