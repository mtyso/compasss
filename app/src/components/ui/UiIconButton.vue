<script setup lang="ts">
// Round icon-only button. The visible glyph is small, the touch target is 44px.
withDefaults(defineProps<{ label: string; tone?: 'plain' | 'filled' | 'on-hero'; size?: 'sm' | 'md' }>(), {
  tone: 'plain',
  size: 'md',
})
</script>

<template>
  <button
    type="button"
    :aria-label="label"
    :title="label"
    class="press grid shrink-0 place-items-center rounded-full disabled:opacity-30"
    :class="[
      size === 'sm' ? 'size-9' : 'size-11',
      tone === 'filled' ? 'bg-surface-2 text-ink' : tone === 'on-hero' ? 'bg-white/10 text-on-hero' : 'text-ink-2 active:bg-ink/8',
    ]"
  >
    <slot />
  </button>
</template>
