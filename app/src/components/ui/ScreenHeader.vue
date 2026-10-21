<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronLeft } from 'lucide-vue-next'

/**
 * Screen title. The large title scrolls away with the content; once it is gone, a compact bar with
 * the same title fades in at the top, so you always know where you are. With `back`, the bar also
 * holds the back button (hidden inside Telegram, which shows its own).
 *
 * Slots: `overline` (small line above the title, e.g. the date), `trailing` (next to the large
 * title: a month switcher, an add button), `actions` (in the compact bar, right side).
 */
const props = withDefaults(defineProps<{ title: string; back?: string | boolean; overline?: string }>(), {
  back: false,
  overline: undefined,
})

const router = useRouter()
const { t } = useI18n()

const titleEl = ref<HTMLElement | null>(null)
const collapsed = ref(false)
const inTelegram = typeof window !== 'undefined' && !!window.Telegram?.WebApp && !!(window.Telegram.WebApp as { initData?: string }).initData
const showBack = computed(() => props.back !== false && !inTelegram)

function goBack(): void {
  if (window.history.state?.back) router.back()
  else router.replace(typeof props.back === 'string' ? props.back : '/')
}

let scroller: HTMLElement | null = null
let frame = 0

function measure(): void {
  frame = 0
  const title = titleEl.value
  if (!title || !scroller) return
  const top = scroller.getBoundingClientRect().top + parseFloat(getComputedStyle(scroller).paddingTop)
  collapsed.value = title.getBoundingClientRect().bottom < top + 44
}

function onScroll(): void {
  if (!frame) frame = requestAnimationFrame(measure)
}

onMounted(() => {
  scroller = titleEl.value?.closest<HTMLElement>('[data-scroller]') ?? null
  scroller?.addEventListener('scroll', onScroll, { passive: true })
  measure()
})
onBeforeUnmount(() => {
  scroller?.removeEventListener('scroll', onScroll)
  cancelAnimationFrame(frame)
})
</script>

<template>
  <div class="contents">
    <!-- Compact bar. With a back button it takes its own row; without, it only appears over the content. -->
    <div class="bar sticky top-0 z-20 -mx-4" :class="showBack ? 'h-12' : 'h-0'">
      <div
        class="absolute inset-x-0 top-0 flex h-12 items-center gap-1 px-2 transition-[background-color,box-shadow] duration-200"
        :class="collapsed ? 'bg-bg shadow-[0_1px_0_var(--c-line)]' : showBack ? 'bg-bg' : 'pointer-events-none'"
      >
        <button
          v-if="showBack"
          type="button"
          class="press grid size-11 shrink-0 place-items-center rounded-full text-ink active:bg-ink/8"
          :aria-label="t('common.back')"
          @click="goBack"
        >
          <ChevronLeft class="size-6" :stroke-width="2.2" />
        </button>
        <span
          class="compact min-w-0 flex-1 truncate text-[1.0625rem] font-semibold"
          :class="[collapsed ? 'is-on' : '', showBack ? '' : 'pl-3']"
          :aria-hidden="!collapsed"
        >
          {{ title }}
        </span>
        <span v-if="$slots.actions" class="flex shrink-0 items-center"><slot name="actions" /></span>
      </div>
    </div>

    <header class="flex items-end gap-3 px-1" :class="showBack ? '' : 'pt-2'">
      <div class="flex min-w-0 flex-1 flex-col gap-1">
        <span v-if="overline || $slots.overline" class="text-caption font-medium text-ink-3">
          <slot name="overline">{{ overline }}</slot>
        </span>
        <h1 ref="titleEl" class="font-display text-title font-bold text-balance">{{ title }}</h1>
      </div>
      <div v-if="$slots.trailing" class="flex shrink-0 items-center pb-0.5"><slot name="trailing" /></div>
    </header>
  </div>
</template>

<style scoped>
/* The bar is a flex item of the screen's column; cancel the column gap so it adds no space of its own. */
.bar {
  margin-bottom: calc(-1 * var(--page-gap, 1.25rem));
}

.compact {
  opacity: 0;
  transform: translateY(6px);
  transition:
    opacity 140ms var(--ease-standard),
    transform 220ms var(--ease-standard);
}

.compact.is-on {
  opacity: 1;
  transform: none;
}
</style>
