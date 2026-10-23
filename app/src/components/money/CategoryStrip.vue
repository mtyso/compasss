<script setup lang="ts">
import { computed } from 'vue'
import { CATEGORY_COLORS } from '@/lib/palette'

/**
 * The month's spending as one strip: a segment per category, as long as its share and in its
 * colour, biggest first, so «where did the money go» reads before any number. The list under it
 * is the legend (same colours on the icons). Slivers too thin to see are merged into a grey end.
 * When the strip first appears the segments grow in one after another, left to right.
 */
const props = defineProps<{ rows: { id: number; color?: string | null; total: number }[] }>()

const segments = computed(() => {
  const sorted = props.rows.filter((row) => row.total > 0).sort((a, b) => b.total - a.total)
  const sum = sorted.reduce((total, row) => total + row.total, 0)
  if (!sum) return []
  const shown = sorted.filter((row) => row.total / sum >= 0.012)
  const rest = sum - shown.reduce((total, row) => total + row.total, 0)
  return [
    ...shown.map((row) => ({ key: String(row.id), color: CATEGORY_COLORS[row.color ?? 'gray'] ?? CATEGORY_COLORS.gray, share: row.total / sum })),
    ...(rest > 0 ? [{ key: 'rest', color: 'color-mix(in srgb, var(--c-ink) 22%, transparent)', share: rest / sum }] : []),
  ]
})
</script>

<template>
  <div v-if="segments.length" class="strip flex h-2.5 gap-[3px] overflow-hidden rounded-full" aria-hidden="true">
    <span
      v-for="(segment, index) in segments"
      :key="segment.key"
      class="segment h-full"
      :style="{ flexGrow: segment.share, backgroundColor: segment.color, transitionDelay: `${index * 45}ms` }"
    />
  </div>
</template>

<style scoped>
.segment {
  flex-basis: 0;
  min-width: 3px;
  transform-origin: left;
  border-radius: 2px;
  transition:
    transform 620ms var(--ease-spring),
    opacity 240ms var(--ease-standard);
}

@starting-style {
  .segment {
    transform: scaleX(0);
    opacity: 0;
  }
}
</style>
