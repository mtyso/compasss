<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import UiSpinner from './UiSpinner.vue'

/**
 * The app's button. `primary` is the one main action of a screen or sheet; `secondary` sits next to
 * it; `ghost` is for quiet actions in text; `danger` deletes; `telegram` is the sign-in button.
 * Pass `to` for a router link or `href` for an external link.
 */
const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'danger-soft' | 'telegram'
    size?: 'sm' | 'md' | 'lg'
    block?: boolean
    loading?: boolean
    disabled?: boolean
    type?: 'button' | 'submit'
    to?: RouteLocationRaw
    href?: string
  }>(),
  { variant: 'primary', size: 'md', block: false, loading: false, disabled: false, type: 'button', to: undefined, href: undefined },
)

const tag = computed(() => (props.to ? RouterLink : props.href ? 'a' : 'button'))

const variants = {
  primary: 'bg-ink text-surface',
  secondary: 'bg-surface text-ink shadow-card',
  ghost: 'text-ink-2 active:bg-ink/8',
  danger: 'bg-danger text-white',
  'danger-soft': 'bg-danger-soft text-danger',
  telegram: 'bg-[#2aabee] text-white dark:bg-[#2b9fd9]',
} as const

const sizes = {
  sm: 'h-9 gap-1.5 rounded-xl px-3.5 text-sm',
  md: 'h-12 gap-2 rounded-control px-5 text-body',
  lg: 'h-14 gap-2.5 rounded-[1.125rem] px-6 text-base',
} as const
</script>

<template>
  <component
    :is="tag"
    :to="to"
    :href="href"
    :type="tag === 'button' ? type : undefined"
    :disabled="tag === 'button' ? disabled || loading : undefined"
    :aria-disabled="disabled || loading || undefined"
    :aria-busy="loading || undefined"
    class="press relative inline-flex shrink-0 items-center justify-center font-semibold whitespace-nowrap disabled:opacity-40"
    :class="[variants[variant], sizes[size], { 'w-full': block, 'pointer-events-none opacity-40': tag !== 'button' && disabled }]"
  >
    <!-- The label stays in place while loading, so the button never changes width. -->
    <span class="inline-flex items-center gap-[inherit] transition-opacity duration-150" :class="{ 'opacity-0': loading }">
      <slot name="icon" />
      <slot />
    </span>
    <span v-if="loading" class="absolute inset-0 grid place-items-center">
      <UiSpinner :size="size === 'sm' ? 16 : 20" />
    </span>
  </component>
</template>
