<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import UiButton from '@/components/ui/UiButton.vue'
import { isoDate, isoTime } from '@/lib/dates'
import { haptics } from '@/lib/haptics'
import { compassPoint, headingFrom, kaabaDistance, qiblaBearing, sunOverQibla, telegramHeading, turnTo } from '@/lib/qibla'
import { telegramApp } from '@/lib/telegram'

/**
 * The Qibla compass (docs/PLAN.md, D40). With a compass sensor (a phone, the Android app, Telegram on a phone)
 * the card turns under a fixed top mark, and the Kaaba arrives at the mark when the phone faces the Qibla.
 * Without one (a computer) the card stays north up and the arrow shows the direction, with the time the sun
 * itself stands there to face.
 */
const props = defineProps<{ lat: number; lon: number; declination: number }>()

const { t } = useI18n()

/** Facing the Qibla this close, in degrees, counts as facing it. */
const FACING = 4
/** How much of each new reading the card takes: a little damping against the sensor's shiver. */
const SMOOTHING = 0.3
/** No reading this long after starting: there is no compass here. */
const NO_SENSOR_MS = 2000

const bearing = computed(() => qiblaBearing(props.lat, props.lon))
const distance = computed(() => Math.round(kaabaDistance(props.lat, props.lon)).toLocaleString('ru-RU'))

type Sensor = 'waiting' | 'ask' | 'live' | 'none'
const sensor = ref<Sensor>('waiting')
/** The card's turn, unwrapped (it may run past 360) so it never spins the long way round. */
const shown = ref(0)
const heading = computed(() => ((shown.value % 360) + 360) % 360)
const turn = computed(() => turnTo(heading.value, bearing.value))
const facing = computed(() => sensor.value === 'live' && Math.abs(turn.value) <= FACING)

watch(facing, (now) => {
  if (now) haptics.impact('medium')
})

// The sun's line follows the clock: «будет» turns into «стояло», and a new day finds the new time.
const now = ref(new Date())
const day = computed(() => isoDate(now.value))
const sunAt = computed(() => sunOverQibla(day.value, props.lat, props.lon, bearing.value))
const sun = computed(() => {
  const at = sunAt.value
  if (!at) return t('prayer.qibla.sunNone')
  return t(at.getTime() > now.value.getTime() ? 'prayer.qibla.sunAt' : 'prayer.qibla.sunWas', { time: isoTime(at) })
})

const ticks = Array.from({ length: 72 }, (_, i) => i * 5)

let gotReading = false
let asked = false
let timer: ReturnType<typeof setTimeout> | undefined
let clock: ReturnType<typeof setInterval> | undefined
let stop: (() => void) | undefined

function screenAngle(): number {
  return screen.orientation?.angle ?? 0
}

function take(next: number | null): void {
  if (next === null) return
  if (!gotReading) {
    gotReading = true
    sensor.value = 'live'
    shown.value = next
    return
  }
  shown.value += turnTo(heading.value, next) * SMOOTHING
}

function listenWindow(): void {
  const absolute = (e: DeviceOrientationEvent) => take(headingFrom({ alpha: e.alpha }, props.declination, screenAngle()))
  // iOS reports the heading itself; Android only counts as a compass when the reading is against north.
  const relative = (e: DeviceOrientationEvent) => {
    const compass = (e as DeviceOrientationEvent & { webkitCompassHeading?: number }).webkitCompassHeading
    if (typeof compass === 'number' || e.absolute) take(headingFrom({ alpha: e.alpha, webkitCompassHeading: compass }, props.declination, screenAngle()))
  }
  window.addEventListener('deviceorientationabsolute', absolute as EventListener)
  window.addEventListener('deviceorientation', relative)
  stop = () => {
    window.removeEventListener('deviceorientationabsolute', absolute as EventListener)
    window.removeEventListener('deviceorientation', relative)
  }
  waitForReading()
}

/** Telegram on a phone gives the sensor through its own API (Bot API 8.0): angles in radians, by platform. */
function listenTelegram(): boolean {
  const app = telegramApp()
  const orientation = app?.DeviceOrientation
  if (!app || !orientation || !app.isVersionAtLeast('8.0')) return false
  const changed = () => {
    if (orientation.absolute && orientation.alpha !== null) take(telegramHeading(orientation.alpha, app.platform, props.declination, screenAngle()))
  }
  const failed = () => {
    if (!gotReading) sensor.value = 'none'
  }
  app.onEvent('deviceOrientationChanged', changed)
  app.onEvent('deviceOrientationFailed', failed)
  orientation.start({ refresh_rate: 50, need_absolute: true }, (started) => {
    if (!started) failed()
  })
  stop = () => {
    app.offEvent('deviceOrientationChanged', changed)
    app.offEvent('deviceOrientationFailed', failed)
    orientation.stop()
  }
  // Telegram asks for the sensor itself.
  asked = true
  waitForReading()
  return true
}

type Permission = { requestPermission?: () => Promise<'granted' | 'denied'> }

/**
 * iOS gives the sensor only after a tap and a yes. Chrome has the same call but answers yes by itself, so the
 * button shows only on a touch screen that stayed silent.
 */
function needsPermission(): boolean {
  const event = typeof DeviceOrientationEvent === 'undefined' ? undefined : (DeviceOrientationEvent as unknown as Permission)
  return typeof event?.requestPermission === 'function' && navigator.maxTouchPoints > 0
}

function waitForReading(): void {
  clearTimeout(timer)
  timer = setTimeout(() => {
    if (!gotReading) sensor.value = !asked && needsPermission() ? 'ask' : 'none'
  }, NO_SENSOR_MS)
}

async function askPermission(): Promise<void> {
  asked = true
  try {
    const answer = await (DeviceOrientationEvent as unknown as Permission).requestPermission?.()
    if (answer !== 'granted') {
      sensor.value = 'none'
      return
    }
    sensor.value = 'waiting'
    waitForReading()
  } catch {
    sensor.value = 'none'
  }
}

onMounted(() => {
  clock = setInterval(() => (now.value = new Date()), 60_000)
  if (listenTelegram()) return
  if (typeof DeviceOrientationEvent === 'undefined') {
    sensor.value = 'none'
    return
  }
  listenWindow()
})

onBeforeUnmount(() => {
  clearTimeout(timer)
  clearInterval(clock)
  stop?.()
})

const headline = computed(() => {
  if (sensor.value !== 'live') return `${Math.round(bearing.value)}°`
  if (facing.value) return t('prayer.qibla.facing')
  const deg = Math.round(Math.abs(turn.value))
  return turn.value < 0 ? t('prayer.qibla.turnLeft', { deg }) : t('prayer.qibla.turnRight', { deg })
})
const bearingLine = computed(() =>
  t('prayer.qibla.bearing', { deg: Math.round(bearing.value), point: t(`prayer.qibla.points.${compassPoint(bearing.value)}`) }),
)
</script>

<template>
  <div class="flex flex-col items-center gap-5">
    <svg
      viewBox="0 0 280 280"
      class="w-full max-w-[17.5rem]"
      role="img"
      :aria-label="`${t('prayer.qibla.title')}: ${bearingLine}`"
    >
      <!-- The phone's own direction: a fixed mark at the top, lit when the Kaaba is under it. -->
      <path
        v-if="sensor === 'live'"
        d="M140 4l7 11h-14z"
        :class="facing ? 'fill-prayer' : 'fill-ink'"
        class="transition-colors duration-200"
      />
      <circle cx="140" cy="140" r="122" fill="none" :class="facing ? 'stroke-prayer' : 'stroke-ink/12'" stroke-width="2" class="transition-colors duration-200" />

      <g :transform="`rotate(${sensor === 'live' ? -shown : 0} 140 140)`">
        <line
          v-for="deg in ticks"
          :key="deg"
          x1="140"
          :y1="deg % 30 === 0 ? 26 : 30"
          x2="140"
          y2="38"
          :class="deg % 90 === 0 ? 'stroke-ink/45' : deg % 30 === 0 ? 'stroke-ink/30' : 'stroke-ink/15'"
          :stroke-width="deg % 30 === 0 ? 2 : 1.25"
          stroke-linecap="round"
          :transform="`rotate(${deg} 140 140)`"
        />
        <text
          x="140"
          y="60"
          text-anchor="middle"
          dominant-baseline="middle"
          class="fill-ink font-semibold"
          font-size="15"
          :transform="`rotate(${sensor === 'live' ? shown : 0} 140 60)`"
        >{{ t('prayer.qibla.north') }}</text>

        <!-- The Qibla: an arrow from the centre to the Kaaba, at its bearing from true north. -->
        <g :transform="`rotate(${bearing} 140 140)`">
          <line x1="140" y1="140" x2="140" y2="66" class="stroke-prayer" stroke-width="3" stroke-linecap="round" />
          <g :transform="`rotate(${-bearing + (sensor === 'live' ? shown : 0)} 140 60)`">
            <rect x="130" y="50" width="20" height="20" rx="2.5" class="fill-ink" />
            <rect x="130" y="55" width="20" height="3" class="fill-prayer" />
          </g>
        </g>
      </g>

      <circle cx="140" cy="140" r="6" class="fill-prayer" />
      <circle cx="140" cy="140" r="2.5" class="fill-bg" />
    </svg>

    <div class="flex flex-col items-center gap-1 text-center" aria-live="polite">
      <p class="num text-[1.375rem] font-semibold" :class="facing ? 'text-prayer' : 'text-ink'">{{ headline }}</p>
      <p class="text-[0.9375rem] text-ink-2">{{ bearingLine }}</p>
      <p class="num text-[0.8125rem] text-ink-3">{{ t('prayer.qibla.distance', { km: distance }) }}</p>
    </div>

    <UiButton v-if="sensor === 'ask'" variant="secondary" @click="askPermission">{{ t('prayer.qibla.start') }}</UiButton>
    <div class="flex max-w-[22rem] flex-col gap-1.5 text-center text-[0.8125rem] text-ink-3">
      <template v-if="sensor === 'live'">
        <p>{{ t('prayer.qibla.flat') }}</p>
        <p>{{ t('prayer.qibla.calibrate') }}</p>
      </template>
      <p v-else-if="sensor === 'none'">{{ t('prayer.qibla.noSensor') }}</p>
      <p>{{ sun }}</p>
    </div>
  </div>
</template>
