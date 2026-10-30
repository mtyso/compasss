<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { showToast } from '@/components/ui/toast'
import { isoDate } from '@/lib/dates'
import { formatDayMonth } from '@/lib/format'
import { haptics } from '@/lib/haptics'
import type { PrayerName, PrayerState } from '@/lib/prayer'
import { usePrayer } from '@/stores/prayer'
import { useSettings } from '@/stores/settings'

/**
 * A prayer past its time, or already marked (docs/PLAN.md, D40): prayed in its time but marked late,
 * made up (qaza), or the mark taken back.
 */
const props = defineProps<{ target: { date: string; name: PrayerName; state: PrayerState; start?: string; end?: string } | null }>()
const open = defineModel<boolean>('open', { required: true })

const prayer = usePrayer()
const settings = useSettings()
const { t } = useI18n()
const busy = ref<'on_time' | 'qaza' | 'unmark' | null>(null)

const title = computed(() => {
  const p = props.target
  if (!p) return ''
  const name = t(`prayer.names.${p.name}`)
  return p.date === isoDate() ? name : `${name} · ${formatDayMonth(p.date, settings.locale)}`
})
const marked = computed(() => props.target?.state === 'done' || props.target?.state === 'qaza')

async function run(action: 'on_time' | 'qaza' | 'unmark'): Promise<void> {
  const p = props.target
  if (!p || busy.value) return
  busy.value = action
  try {
    if (action === 'unmark') await prayer.unmark(p.date, p.name)
    else await prayer.mark(p.date, p.name, action)
    haptics.notify('success')
    open.value = false
  } catch {
    haptics.notify('error')
    showToast({ message: t('prayer.markFailed'), tone: 'danger' })
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <UiSheet v-model:open="open" :title="title">
    <p v-if="target?.start && target.end" class="num text-[0.9375rem] text-ink-2">{{ $t('prayer.sheet.window', { start: target.start, end: target.end }) }}</p>
    <template #footer>
      <div class="flex flex-col gap-2">
        <UiButton v-if="target?.state !== 'done'" block :loading="busy === 'on_time'" @click="run('on_time')">{{ $t('prayer.sheet.inTime') }}</UiButton>
        <UiButton v-if="target?.state !== 'qaza'" block :variant="target?.state === 'done' ? 'primary' : 'secondary'" :loading="busy === 'qaza'" @click="run('qaza')">{{ $t('prayer.sheet.qaza') }}</UiButton>
        <UiButton v-if="marked" block variant="ghost" :loading="busy === 'unmark'" @click="run('unmark')">{{ $t('prayer.sheet.unmark') }}</UiButton>
      </div>
    </template>
  </UiSheet>
</template>
