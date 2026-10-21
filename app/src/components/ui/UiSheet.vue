<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import { X } from 'lucide-vue-next'
import { useI18n } from 'vue-i18n'
import { haptics } from '@/lib/haptics'
import { easingOf, prefersReducedMotion, springs } from '@/lib/motion'
import { pushOverlay } from './overlays'

/**
 * Bottom sheet. Slides up on a spring, follows the finger when pulled down (by the handle, the
 * header, or the content once it is scrolled to the top) and closes on a long or fast pull.
 * Esc, the scrim, the close button and the Android/Telegram back button close it too.
 *
 * Slots: `header` replaces the title row, the default slot scrolls, `footer` stays pinned.
 */
const props = withDefaults(defineProps<{ title?: string; label?: string; closeButton?: boolean }>(), {
  title: undefined,
  label: undefined,
  closeButton: true,
})
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ opened: []; closed: [] }>()

const { t } = useI18n()
const titleId = useId()

const panel = ref<HTMLElement | null>(null)
const scrim = ref<HTMLElement | null>(null)
const content = ref<HTMLElement | null>(null)
const footerDivider = ref(false)

let returnFocus: HTMLElement | null = null
let removeOverlay: (() => void) | null = null

function close(): void {
  open.value = false
}

watch(
  open,
  (value) => {
    if (value) {
      returnFocus = document.activeElement as HTMLElement | null
      removeOverlay = pushOverlay(close)
    } else {
      removeOverlay?.()
      removeOverlay = null
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => removeOverlay?.())

/* ---------- Enter and leave ---------- */

/** The scrim and the sheet, found from the transition's element: template refs are already unset by the time leave runs. */
function parts(el: Element): { shade: HTMLElement; sheet: HTMLElement } {
  return { shade: el.children[0] as HTMLElement, sheet: el.children[1] as HTMLElement }
}

function onEnter(el: Element, done: () => void): void {
  const { shade, sheet } = parts(el)
  if (prefersReducedMotion()) {
    shade.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 150 })
    sheet.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 150 }).finished.then(entered(done))
    return
  }
  shade.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 260, easing: 'cubic-bezier(0.2, 0, 0, 1)' })
  sheet
    .animate([{ transform: 'translateY(100%)' }, { transform: 'translateY(0)' }], {
      duration: springs.ui.duration,
      easing: easingOf(springs.ui),
    })
    .finished.then(entered(done), done)
}

function entered(done: () => void): () => void {
  return () => {
    done()
    emit('opened')
    updateDivider()
    // Keep focus inside the dialog unless a field inside already took it (the amount input does).
    if (!panel.value?.contains(document.activeElement)) panel.value?.focus({ preventScroll: true })
  }
}

function onLeave(el: Element, done: () => void): void {
  const { shade, sheet } = parts(el)
  const from = sheet.style.transform || 'translateY(0)'
  const shadeFrom = shade.style.opacity || '1'
  const finish = (): void => {
    done()
    emit('closed')
    returnFocus?.focus?.({ preventScroll: true })
    returnFocus = null
  }
  if (prefersReducedMotion()) {
    sheet.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 120, fill: 'forwards' }).finished.then(finish, finish)
    return
  }
  shade.animate([{ opacity: shadeFrom }, { opacity: 0 }], { duration: 200, easing: 'linear', fill: 'forwards' })
  sheet
    .animate([{ transform: from }, { transform: 'translateY(100%)' }], {
      duration: 220,
      easing: 'cubic-bezier(0.3, 0, 0.8, 0.15)',
      fill: 'forwards',
    })
    .finished.then(finish, finish)
}

/* ---------- Pull to dismiss ---------- */

let startX = 0
let startY = 0
let offset = 0
let axis: 'y' | 'x' | null = null
let dragging = false
let samples: { y: number; t: number }[] = []

function begin(x: number, y: number): void {
  startX = x
  startY = y
  offset = 0
  axis = null
  dragging = false
  samples = [{ y, t: performance.now() }]
}

/** Returns true while the gesture belongs to the sheet. */
function move(x: number, y: number, fromContent: boolean): boolean {
  const dx = x - startX
  const dy = y - startY
  if (axis === null) {
    if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return false
    axis = Math.abs(dy) > Math.abs(dx) ? 'y' : 'x'
    // From the content, only a pull down at the very top moves the sheet; anything else scrolls.
    dragging = axis === 'y' && (!fromContent || ((content.value?.scrollTop ?? 0) <= 0 && dy > 0))
  }
  if (!dragging) return false
  // Pulling up past the top stretches a little and stops, like the edge of a scroll view.
  offset = dy >= 0 ? dy : -18 * (1 - Math.exp(dy / 60))
  apply(offset)
  const now = performance.now()
  samples.push({ y, t: now })
  while (samples.length > 2 && now - samples[0]!.t > 90) samples.shift()
  return true
}

function apply(value: number): void {
  const sheet = panel.value
  if (!sheet) return
  sheet.style.transform = `translateY(${value}px)`
  if (scrim.value) scrim.value.style.opacity = String(1 - Math.min(Math.max(value, 0) / sheet.offsetHeight, 1) * 0.85)
}

function end(): void {
  if (!dragging) return
  dragging = false
  const sheet = panel.value
  if (!sheet) return
  const first = samples[0]!
  const last = samples.at(-1)!
  const velocity = (last.y - first.y) / Math.max(last.t - first.t, 1) // px per ms, down is positive
  if (offset > sheet.offsetHeight * 0.3 || (velocity > 0.55 && offset > 24)) {
    haptics.impact('light')
    close()
    return
  }
  // Not far enough: spring back.
  const from = offset
  sheet
    .animate([{ transform: `translateY(${from}px)` }, { transform: 'translateY(0)' }], {
      duration: springs.ui.duration,
      easing: easingOf(springs.ui),
    })
    .finished.then(() => {
      sheet.style.transform = ''
    })
  sheet.style.transform = ''
  if (scrim.value) {
    scrim.value.animate([{ opacity: scrim.value.style.opacity || '1' }, { opacity: 1 }], { duration: 200 })
    scrim.value.style.opacity = ''
  }
}

// Handle and header: pointer events (also work with a mouse on the web).
function onPointerDown(event: PointerEvent): void {
  if (event.button !== 0 || (event.target as HTMLElement).closest('button, input, a, [role="radio"]')) return
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
  begin(event.clientX, event.clientY)
}
function onPointerMove(event: PointerEvent): void {
  if (samples.length) move(event.clientX, event.clientY, false)
}
function onPointerUp(): void {
  end()
  samples = []
}

// Content: touch events, so a pull at the top can take over from scrolling (needs passive: false).
function onTouchStart(event: TouchEvent): void {
  const touch = event.touches[0]
  if (event.touches.length === 1 && touch) begin(touch.clientX, touch.clientY)
}
function onTouchMove(event: TouchEvent): void {
  const touch = event.touches[0]
  if (touch && samples.length && move(touch.clientX, touch.clientY, true) && event.cancelable) event.preventDefault()
}
function onTouchEnd(): void {
  end()
  samples = []
}

watch(content, (element, previous) => {
  previous?.removeEventListener('touchmove', onTouchMove)
  element?.addEventListener('touchmove', onTouchMove, { passive: false })
})

/* ---------- Footer divider: only while content continues below it ---------- */

function updateDivider(): void {
  const element = content.value
  footerDivider.value = !!element && element.scrollTop + element.clientHeight < element.scrollHeight - 1
}

const ariaLabel = computed(() => (props.title ? undefined : props.label))

watch(open, async (value) => {
  if (value) {
    await nextTick()
    updateDivider()
  }
})
</script>

<template>
  <Teleport to="body">
    <Transition :css="false" @enter="onEnter" @leave="onLeave">
      <div v-if="open" class="fixed inset-0 z-40 flex items-end justify-center">
        <div ref="scrim" class="absolute inset-0 bg-scrim" aria-hidden="true" @click="close" />
        <div
          ref="panel"
          class="sheet relative flex w-full max-w-xl flex-col rounded-t-[1.75rem] bg-raised shadow-raised outline-none will-change-transform"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="title ? titleId : undefined"
          :aria-label="ariaLabel"
          tabindex="-1"
          @keydown.esc.stop="close"
        >
          <div
            class="touch-none cursor-grab select-none active:cursor-grabbing"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
          >
            <div class="flex justify-center pt-2.5 pb-1.5" aria-hidden="true">
              <span class="h-1 w-9 rounded-full bg-ink/15" />
            </div>
            <slot name="header">
              <header v-if="title || closeButton" class="flex min-h-11 items-center gap-3 pr-3 pb-2 pl-5">
                <h2 :id="titleId" class="flex-1 truncate font-display text-headline font-bold">{{ title }}</h2>
                <button
                  v-if="closeButton"
                  type="button"
                  class="press grid size-9 place-items-center rounded-full bg-surface-2 text-ink-2"
                  :aria-label="t('ui.close')"
                  @click="close"
                >
                  <X class="size-[18px]" :stroke-width="2.4" />
                </button>
              </header>
            </slot>
          </div>

          <div
            ref="content"
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 pb-5"
            :class="{ 'pb-[max(1.25rem,var(--safe-bottom))]': !$slots.footer }"
            @scroll.passive="updateDivider"
            @touchstart.passive="onTouchStart"
            @touchend="onTouchEnd"
            @touchcancel="onTouchEnd"
          >
            <slot />
          </div>

          <footer
            v-if="$slots.footer"
            class="px-5 pt-3 pb-[max(1rem,var(--safe-bottom))] transition-shadow duration-200"
            :class="{ 'shadow-[0_-1px_0_var(--c-line)]': footerDivider }"
          >
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.sheet {
  max-height: calc(100dvh - var(--safe-top) - 0.75rem);
}
</style>
