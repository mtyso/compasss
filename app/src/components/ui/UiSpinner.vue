<script setup lang="ts">
// A thin arc that rotates; inherits the text colour. Spins on the compositor (transform only).
withDefaults(defineProps<{ size?: number }>(), { size: 20 })
</script>

<template>
  <svg class="ui-spinner shrink-0" :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <circle cx="12" cy="12" r="9.5" stroke="currentColor" stroke-opacity="0.2" stroke-width="2.5" />
    <path d="M21.5 12a9.5 9.5 0 0 0-9.5-9.5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
  </svg>
</template>

<style scoped>
.ui-spinner {
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* A spinner is functional feedback, so it keeps turning, just slower. */
@media (prefers-reduced-motion: reduce) {
  .ui-spinner {
    animation-duration: 1.6s !important;
    animation-iteration-count: infinite !important;
  }
}
</style>
