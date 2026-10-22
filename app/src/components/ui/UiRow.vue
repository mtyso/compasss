<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { ChevronRight } from 'lucide-vue-next'

/**
 * A row in a grouped list (`.group`): leading icon, title and subtitle, trailing value.
 * With `to` it is a link and gets a chevron; with a click listener it is a button.
 */
const props = withDefaults(
  defineProps<{ title?: string; subtitle?: string; to?: RouteLocationRaw; chevron?: boolean; tone?: 'default' | 'danger' | 'muted' }>(),
  { title: undefined, subtitle: undefined, to: undefined, chevron: undefined, tone: 'default' },
)
defineEmits<{ click: [event: MouseEvent] }>()

const tag = computed(() => (props.to ? RouterLink : 'button'))
const showChevron = computed(() => props.chevron ?? !!props.to)
</script>

<template>
  <component
    :is="tag"
    :to="to"
    :type="to ? undefined : 'button'"
    class="press-soft flex min-h-14 w-full items-center gap-3 px-4 py-3 text-left"
    :class="{ 'text-danger': tone === 'danger', 'text-ink-2': tone === 'muted' }"
    @click="$emit('click', $event)"
  >
    <span v-if="$slots.leading" class="flex shrink-0 items-center" :class="{ 'text-ink-2': tone === 'default' }"><slot name="leading" /></span>
    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
      <span class="truncate font-medium"><slot>{{ title }}</slot></span>
      <span v-if="subtitle || $slots.subtitle" class="truncate text-caption text-ink-3"><slot name="subtitle">{{ subtitle }}</slot></span>
    </span>
    <span v-if="$slots.trailing" class="flex shrink-0 items-center gap-1"><slot name="trailing" /></span>
    <ChevronRight v-if="showChevron" class="-mr-1 size-[18px] shrink-0 text-ink-3" :stroke-width="2.2" />
  </component>
</template>
