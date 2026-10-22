<script setup lang="ts">
const open = defineModel<boolean>('open', { required: true })
</script>

<template>
  <Transition
    enter-from-class="opacity-0"
    leave-to-class="opacity-0"
    enter-active-class="transition duration-200"
    leave-active-class="transition duration-150"
  >
    <div v-if="open" class="fixed inset-0 z-20 flex items-end bg-black/40" @click.self="open = false">
      <div
        class="mx-auto w-full max-w-xl rounded-t-3xl bg-surface px-5 pt-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]"
        role="dialog"
        aria-modal="true"
        :aria-label="$t('quickAdd.title')"
      >
        <h2 class="font-display text-lg font-bold">{{ $t('quickAdd.title') }}</h2>
        <p class="mt-2 text-sm text-ink-2">{{ $t('quickAdd.soon') }}</p>
        <button
          type="button"
          class="mt-5 w-full rounded-2xl bg-ink py-3 text-sm font-semibold text-surface"
          @click="open = false"
        >
          {{ $t('quickAdd.close') }}
        </button>
      </div>
    </div>
  </Transition>
</template>
