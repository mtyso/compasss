<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { prefersReducedMotion } from '@/lib/motion'

/**
 * A number set like a mechanical counter: each digit is a column of 0–9 that rolls to its value,
 * so a changed sum turns over the way the dial of a meter does instead of flickering through
 * every value in between. Columns are keyed from the right (units stay units), a column that
 * appears slides in, and the rolls start a few milliseconds apart from the left.
 *
 * `intro` rolls the digits up from zero when the number first appears (the day's limit on
 * «Сегодня»); otherwise the first value is simply shown. Text only: the parent sets the label.
 */
const props = defineProps<{ text: string; intro?: boolean }>()

const ready = ref(!props.intro || prefersReducedMotion())
onMounted(() => {
  if (!ready.value) requestAnimationFrame(() => requestAnimationFrame(() => (ready.value = true)))
})

const chars = computed(() => {
  const list = [...props.text]
  let digits = 0
  return list
    .map((char, index) => {
      const isDigit = char >= '0' && char <= '9'
      const fromRight = list.length - index
      if (isDigit) digits++
      return { char, isDigit, key: `${fromRight}`, value: isDigit ? Number(char) : 0, order: digits }
    })
    .map((cell) => ({ ...cell, delay: (cell.order - 1) * 28 }))
})
</script>

<template>
  <span class="rolling">
    <template v-for="cell in chars" :key="cell.key">
      <span v-if="cell.isDigit" class="roll-col">
        <span class="roll-ghost">{{ cell.char }}</span>
        <span class="roll-window">
          <span class="roll-strip" :style="{ '--d': ready ? cell.value : 0, transitionDelay: `${cell.delay}ms` }">
            <span v-for="n in 10" :key="n">{{ n - 1 }}</span>
          </span>
        </span>
      </span>
      <span v-else class="roll-sep">{{ cell.char }}</span>
    </template>
  </span>
</template>

<style scoped>
.rolling {
  display: inline-flex;
  align-items: baseline;
}

/* The ghost digit gives the column its width and the baseline; the strip rolls behind a window. */
.roll-col {
  position: relative;
  display: inline-block;
  line-height: 1.15;
}

.roll-ghost {
  visibility: hidden;
}

.roll-window {
  position: absolute;
  inset: 0;
  overflow: hidden;
  -webkit-mask-image: linear-gradient(transparent, #000 14%, #000 86%, transparent);
  mask-image: linear-gradient(transparent, #000 14%, #000 86%, transparent);
}

.roll-strip {
  position: absolute;
  inset-inline: 0;
  top: 0;
  display: flex;
  flex-direction: column;
  transform: translateY(calc(var(--d) * -1.15em));
  transition: transform 760ms var(--ease-spring);
}

.roll-strip > span {
  display: block;
  height: 1.15em;
}

.roll-sep {
  white-space: pre;
}

@media (prefers-reduced-motion: reduce) {
  .roll-strip {
    transition: none;
  }
}
</style>
