<script setup lang="ts">
import { ref } from 'vue'
import { Check, CircleAlert, TriangleAlert } from 'lucide-vue-next'
import { easingOf, springs } from '@/lib/motion'
import { dismissToast, holdToast, releaseToast, toastState } from './toast'

// Renders the current toast. Swipe it sideways to dismiss it early.

const card = ref<HTMLElement | null>(null)
let startX = 0
let dx = 0
let dragging = false

function onDown(event: PointerEvent): void {
  if ((event.target as HTMLElement).closest('button')) return
  dragging = true
  startX = event.clientX
  dx = 0
  holdToast()
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
}

function onMove(event: PointerEvent): void {
  if (!dragging || !card.value) return
  dx = event.clientX - startX
  card.value.style.transform = `translateX(${dx}px)`
  card.value.style.opacity = String(1 - Math.min(Math.abs(dx) / 220, 0.8))
}

function onUp(): void {
  if (!dragging || !card.value) return
  dragging = false
  const element = card.value
  if (Math.abs(dx) > 80) {
    element.animate([{ transform: `translateX(${dx}px)`, opacity: element.style.opacity }, { transform: `translateX(${Math.sign(dx) * 360}px)`, opacity: 0 }], {
      duration: 180,
      easing: 'cubic-bezier(0.3, 0, 0.8, 0.15)',
      fill: 'forwards',
    }).finished.then(() => dismissToast())
    return
  }
  element.style.transform = ''
  element.style.opacity = ''
  element.animate([{ transform: `translateX(${dx}px)` }, { transform: 'translateX(0)' }], { duration: springs.ui.duration, easing: easingOf(springs.ui) })
  releaseToast()
}

async function run(): Promise<void> {
  const action = toastState.current?.action
  dismissToast()
  await action?.run()
}
</script>

<template>
  <div class="pointer-events-none flex justify-center px-4" aria-live="polite">
    <Transition name="toast">
      <div
        v-if="toastState.current"
        ref="card"
        :key="toastState.current.id"
        class="pointer-events-auto flex w-full max-w-sm touch-pan-y items-center gap-3 rounded-[1.125rem] bg-ink py-2.5 pr-2 pl-3.5 text-surface shadow-raised"
        role="status"
        @pointerdown="onDown"
        @pointermove="onMove"
        @pointerup="onUp"
        @pointercancel="onUp"
      >
        <span
          v-if="toastState.current.tone && toastState.current.tone !== 'default'"
          class="grid size-7 shrink-0 place-items-center rounded-full"
          :class="{
            'bg-money text-white dark:text-surface': toastState.current.tone === 'success',
            'bg-habit text-white': toastState.current.tone === 'warning',
            'bg-danger text-white': toastState.current.tone === 'danger',
          }"
        >
          <Check v-if="toastState.current.tone === 'success'" class="size-4" :stroke-width="3" />
          <TriangleAlert v-else-if="toastState.current.tone === 'warning'" class="size-4" :stroke-width="2.4" />
          <CircleAlert v-else class="size-4" :stroke-width="2.4" />
        </span>
        <span class="flex min-w-0 flex-1 flex-col py-0.5">
          <span class="truncate text-sm font-semibold">{{ toastState.current.message }}</span>
          <span v-if="toastState.current.detail" class="num truncate text-caption opacity-70">{{ toastState.current.detail }}</span>
        </span>
        <button
          v-if="toastState.current.action"
          type="button"
          class="press h-9 shrink-0 rounded-xl px-3 text-sm font-semibold text-[#7be0b8] active:bg-white/10 dark:text-[#0b6a4c] dark:active:bg-black/5"
          @click="run"
        >
          {{ toastState.current.action.label }}
        </button>
        <span v-else class="w-1.5" />
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.toast-enter-active {
  transition:
    transform var(--dur-pop) var(--ease-pop),
    opacity 160ms var(--ease-standard);
}

.toast-leave-active {
  transition:
    transform 160ms var(--ease-exit),
    opacity 160ms var(--ease-exit);
}

.toast-enter-from {
  opacity: 0;
  transform: translateY(16px) scale(0.94);
}

.toast-leave-to {
  opacity: 0;
  transform: translateY(8px) scale(0.98);
}

@media (prefers-reduced-motion: reduce) {
  .toast-enter-from,
  .toast-leave-to {
    transform: none;
  }
}
</style>
