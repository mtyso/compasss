<script setup lang="ts">
import { X } from 'lucide-vue-next'

defineProps<{ title: string }>()
const open = defineModel<boolean>('open', { required: true })
</script>

<template>
  <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition-opacity duration-200" leave-active-class="transition-opacity duration-150">
    <div v-if="open" class="fixed inset-0 z-20 flex items-end bg-black/40" @click.self="open = false" @keydown.esc="open = false">
      <div class="sheet mx-auto flex max-h-[92dvh] w-full max-w-xl flex-col rounded-t-3xl bg-surface" role="dialog" aria-modal="true" :aria-label="title">
        <header class="flex items-center gap-3 px-5 pt-4 pb-3">
          <h2 class="flex-1 truncate font-display text-lg font-bold">{{ title }}</h2>
          <button type="button" class="grid size-9 place-items-center rounded-full text-ink-2 active:bg-line" :aria-label="$t('quickAdd.close')" @click="open = false">
            <X class="size-5" />
          </button>
        </header>
        <div class="flex flex-col gap-5 overflow-y-auto px-5 pb-4">
          <slot />
        </div>
        <footer v-if="$slots.footer" class="flex flex-col gap-2 border-t border-line px-5 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
          <slot name="footer" />
        </footer>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.sheet {
  animation: sheet-up 220ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

@keyframes sheet-up {
  from { transform: translateY(24px); }
  to { transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
  .sheet { animation: none; }
}
</style>
