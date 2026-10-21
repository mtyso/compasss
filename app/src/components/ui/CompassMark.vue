<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { easingOf, prefersReducedMotion, springs } from '@/lib/motion'

/**
 * The Компас mark: a dial and a needle, north half in money green. The needle is its own layer and
 * turns with a transform, so it stays smooth on a phone.
 *
 * `angle` is the bearing in degrees (0 = straight up). With `swing`, the needle arrives from the
 * side and settles on the bearing with a damped spring, the way a real compass needle does.
 */
const props = withDefaults(
  defineProps<{ size?: number; angle?: number; swing?: boolean; tone?: 'default' | 'on-hero'; dial?: boolean }>(),
  { size: 96, angle: 0, swing: false, tone: 'default', dial: true },
)

const needle = ref<HTMLElement | null>(null)
let current = props.angle

function turn(from: number, to: number): void {
  const element = needle.value
  if (!element) return
  element.style.transform = `rotate(${to}deg)`
  current = to
  if (prefersReducedMotion() || from === to) return
  element.animate([{ transform: `rotate(${from}deg)` }, { transform: `rotate(${to}deg)` }], {
    duration: springs.needle.duration,
    easing: easingOf(springs.needle),
  })
}

onMounted(() => {
  if (props.swing) turn(props.angle - 75, props.angle)
  else turn(props.angle, props.angle)
})

watch(
  () => props.angle,
  (to) => turn(current, to),
)

/** Re-runs the settle from a given offset: a nudge after a wrong PIN, a swing on success. */
function swingFrom(offset: number): void {
  turn(current + offset, current)
}
defineExpose({ swingFrom })
</script>

<template>
  <span class="relative inline-grid shrink-0 place-items-center" :style="{ width: `${size}px`, height: `${size}px` }" aria-hidden="true">
    <svg v-if="dial" class="absolute inset-0" viewBox="0 0 100 100" fill="none">
      <circle cx="50" cy="50" r="47" :class="tone === 'on-hero' ? 'stroke-on-hero/20' : 'stroke-ink/15'" stroke-width="2" />
      <g :class="tone === 'on-hero' ? 'stroke-on-hero/35' : 'stroke-ink/30'" stroke-width="2" stroke-linecap="round">
        <path d="M50 8v7M92 50h-7M50 92v-7M8 50h7" />
      </g>
      <g :class="tone === 'on-hero' ? 'stroke-on-hero/15' : 'stroke-ink/15'" stroke-width="1.5" stroke-linecap="round">
        <path d="M71 13.6l-2 3.5M86.4 29l-3.5 2M86.4 71l-3.5-2M71 86.4l-2-3.5M29 86.4l2-3.5M13.6 71l3.5-2M13.6 29l3.5 2M29 13.6l2 3.5" />
      </g>
    </svg>
    <span ref="needle" class="absolute inset-0 will-change-transform">
      <svg viewBox="0 0 100 100" class="size-full" fill="none">
        <path d="M50 17 58.5 50h-17z" class="fill-money stroke-money" stroke-width="2" stroke-linejoin="round" />
        <path d="M41.5 50h17L50 83z" :class="tone === 'on-hero' ? 'fill-on-hero/80 stroke-on-hero/80' : 'fill-ink stroke-ink'" stroke-width="2" stroke-linejoin="round" />
        <circle cx="50" cy="50" r="4.2" :class="tone === 'on-hero' ? 'fill-hero stroke-on-hero/80' : 'fill-bg stroke-ink'" stroke-width="2" />
      </svg>
    </span>
  </span>
</template>
