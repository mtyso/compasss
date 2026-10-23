<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronDown, ChevronLeft, ChevronRight, Plus, SlidersHorizontal } from 'lucide-vue-next'
import UiButton from '@/components/ui/UiButton.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import type { CalendarView } from '@/stores/calendar'

/**
 * The calendar's sticky head. On a phone: back, the period title (a tap opens the month picker),
 * «Сегодня» with today's number, the layers and «+», then the views under them; ‹ › are swipes
 * there. On a wide screen one row: «Сегодня», ‹ ›, the title, then the views, the layers (only
 * while there is no side panel) and «+». A thin bar along its bottom edge shows a range on its way.
 */
const props = defineProps<{
  title: string
  today: string
  wide: boolean
  /** The side panel is there, with the mini month and the layers. */
  panel: boolean
  /** A hairline under the head (the list, which has no sticky part of its own). */
  line: boolean
  busy: boolean
}>()
const view = defineModel<CalendarView>('view', { required: true })
const emit = defineEmits<{ pick: []; today: []; prev: []; next: []; layers: []; add: [] }>()

const router = useRouter()
const { t } = useI18n()

const views = computed(() => (['day', 'week', 'month', 'list'] as const).map((value) => ({ value, label: t(`calendar.views.${value}`) })))

// Telegram draws its own «Back» (see ScreenHeader).
const inTelegram =
  document.documentElement.classList.contains('in-telegram') ||
  !!(window.Telegram?.WebApp as { initData?: string } | undefined)?.initData

function goBack(): void {
  if (window.history.state?.back) router.back()
  else void router.replace({ name: 'tasks' })
}

const todayNumber = computed(() => Number(props.today.slice(8, 10)))
</script>

<template>
  <header
    class="head sticky z-20 bg-bg"
    :class="[wide ? '-top-6 -mt-6 pt-6 pb-4' : 'top-0 px-4 pb-2.5', { 'shadow-[0_1px_0_var(--c-line)]': line }]"
  >
    <!-- Phone -->
    <template v-if="!wide">
      <div class="flex h-12 items-center">
        <button
          v-if="!inTelegram"
          type="button"
          class="press -ml-2.5 grid size-11 shrink-0 place-items-center rounded-full text-ink active:bg-ink/8"
          :aria-label="t('common.back')"
          @click="goBack"
        >
          <ChevronLeft class="size-6" :stroke-width="2.2" />
        </button>
        <h1 class="flex min-w-0 flex-1">
          <button
            type="button"
            class="press flex min-w-0 items-center gap-1 rounded-[0.625rem] py-1.5 pr-1.5 text-left"
            :class="inTelegram ? '' : 'pl-0.5'"
            :aria-label="`${title}, ${t('calendar.pickDate')}`"
            @click="emit('pick')"
          >
            <span class="truncate text-section font-semibold">{{ title }}</span>
            <ChevronDown class="size-4 shrink-0 text-ink-3" :stroke-width="2.4" aria-hidden="true" />
          </button>
        </h1>
        <UiIconButton :label="t('calendar.today')" @click="emit('today')">
          <span class="num grid h-[1.375rem] min-w-[1.375rem] place-items-center rounded-[0.375rem] px-0.5 text-micro font-bold shadow-[inset_0_0_0_1.75px_currentColor]">
            {{ todayNumber }}
          </span>
        </UiIconButton>
        <UiIconButton :label="t('calendar.layers')" @click="emit('layers')">
          <SlidersHorizontal class="size-5" :stroke-width="2" />
        </UiIconButton>
        <UiIconButton tone="filled" :label="t('calendar.newEvent')" class="-mr-0.5" @click="emit('add')">
          <Plus class="size-5" :stroke-width="2.2" />
        </UiIconButton>
      </div>
      <UiSegmented v-model="view" :options="views" :label="t('calendar.title')" size="sm" />
    </template>

    <!-- Wide screen -->
    <div v-else class="flex h-11 items-center gap-2">
      <UiButton variant="secondary" size="sm" @click="emit('today')">{{ t('calendar.today') }}</UiButton>
      <div class="flex shrink-0 items-center">
        <UiIconButton size="sm" :label="t('calendar.prev')" @click="emit('prev')">
          <ChevronLeft class="size-5" :stroke-width="2.2" />
        </UiIconButton>
        <UiIconButton size="sm" :label="t('calendar.next')" @click="emit('next')">
          <ChevronRight class="size-5" :stroke-width="2.2" />
        </UiIconButton>
      </div>
      <h1 class="flex min-w-0 flex-1 pl-1">
        <span v-if="panel" class="truncate text-title font-bold">{{ title }}</span>
        <button
          v-else
          type="button"
          class="press flex min-w-0 items-center gap-1.5 rounded-[0.625rem] text-left"
          :aria-label="`${title}, ${t('calendar.pickDate')}`"
          @click="emit('pick')"
        >
          <span class="truncate text-title font-bold">{{ title }}</span>
          <ChevronDown class="size-5 shrink-0 text-ink-3" :stroke-width="2.4" aria-hidden="true" />
        </button>
      </h1>
      <UiSegmented v-model="view" :options="views" :label="t('calendar.title')" size="sm" class="w-[22rem] shrink-0" />
      <UiIconButton v-if="!panel" :label="t('calendar.layers')" @click="emit('layers')">
        <SlidersHorizontal class="size-5" :stroke-width="2" />
      </UiIconButton>
      <UiIconButton tone="filled" :label="t('calendar.newEvent')" @click="emit('add')">
        <Plus class="size-5" :stroke-width="2.2" />
      </UiIconButton>
    </div>

    <!-- A range on its way that was not prefetched. -->
    <div class="busy pointer-events-none absolute inset-x-0 bottom-0 h-0.5 overflow-hidden" :class="{ 'is-on': busy }" aria-hidden="true">
      <span class="busy-bar absolute inset-y-0 left-0 w-1/3 rounded-full bg-money/60" />
    </div>
  </header>
</template>

<style scoped>
.busy {
  opacity: 0;
  transition: opacity var(--dur-fast) var(--ease-standard);
}

/* Only a wait that is noticed gets the bar: it shows after a short delay. */
.busy.is-on {
  opacity: 1;
  transition-delay: 400ms;
}

.busy-bar {
  animation: busy 1.1s var(--ease-standard) infinite;
}

.busy:not(.is-on) .busy-bar {
  animation-play-state: paused;
}

@keyframes busy {
  from {
    transform: translateX(-100%);
  }
  to {
    transform: translateX(300%);
  }
}
</style>
