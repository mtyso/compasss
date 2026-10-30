import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { showToast } from '@/components/ui/toast'
import { shiftDate } from '@/lib/dates'
import { haptics } from '@/lib/haptics'
import { formatLeft, minutesUntil, tapAction, windowShare } from '@/lib/prayer'
import { usePrayer, type PrayerDay } from '@/stores/prayer'

/** How often «осталось 12 мин» moves on. */
const TICK_MS = 30_000

/**
 * The prayer of the moment for «Сегодня» and «Намаз» (docs/PLAN.md, D40): the one whose time is on, or the next
 * one, with the time left ticking; the day again from the server once a time starts or ends. Also the tap on a
 * prayer: marked at once while its time is on, the choice sheet otherwise.
 */
export function usePrayerNow() {
  const prayer = usePrayer()
  const { t } = useI18n()
  const now = ref(new Date())
  let timer: ReturnType<typeof setInterval> | undefined

  const moment = computed(() => prayer.overview?.moment ?? null)
  /** The one to pray now: its time is on and it is not marked yet. */
  const current = computed(() => {
    const c = moment.value?.current
    if (!c || c.marked) return null
    // Isha's time ends at the next morning's Fajr.
    const endDate = c.name === 'isha' ? shiftDate(c.date, 1) : c.date
    const left = minutesUntil(endDate, c.end, now.value)
    const start = prayer.overview?.date === c.date ? prayer.overview.prayers.find((p) => p.name === c.name)?.start : undefined
    return { ...c, left, leftText: formatLeft(left, t), share: start ? windowShare(c.date, start, c.end, now.value) : null }
  })
  const next = computed(() => {
    const n = moment.value?.next
    if (!n) return null
    const left = minutesUntil(n.date, n.start, now.value)
    return { ...n, left, leftText: formatLeft(left, t), tomorrow: n.date !== prayer.overview?.date }
  })

  function tick(): void {
    now.value = new Date()
    const ended = current.value !== null && current.value.left <= 0
    const started = next.value !== null && next.value.left <= 0
    if (ended || started) void prayer.load().catch(() => undefined)
    else void prayer.ensureLoaded().catch(() => undefined)
  }

  onMounted(() => {
    timer = setInterval(tick, TICK_MS)
  })
  onBeforeUnmount(() => clearInterval(timer))

  // A tap on a prayer: its time on marks it, past or marked opens the choice.
  const sheetOpen = ref(false)
  const sheetTarget = ref<(PrayerDay & { date: string }) | null>(null)
  const marking = ref<string | null>(null)

  async function markNow(date: string, name: PrayerDay['name']): Promise<void> {
    if (marking.value) return
    marking.value = `${date} ${name}`
    try {
      await prayer.mark(date, name)
      haptics.notify('success')
    } catch {
      haptics.notify('error')
      showToast({ message: t('prayer.markFailed'), tone: 'danger' })
    } finally {
      marking.value = null
    }
  }

  function tap(day: PrayerDay, date: string): void {
    const action = tapAction(day.state)
    if (action === 'mark') void markNow(date, day.name)
    else if (action === 'choose') {
      sheetTarget.value = { ...day, date }
      sheetOpen.value = true
    }
  }

  return { now, current, next, marking, markNow, tap, sheetOpen, sheetTarget }
}
