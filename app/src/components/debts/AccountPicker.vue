<script setup lang="ts">
import AccountIcon from '@/components/money/AccountIcon.vue'
import type { Account } from '@/stores/money'

/** The account money comes from or goes to, as a row of chips; the slot adds a choice of its own. */
defineProps<{ accounts: Account[]; label: string }>()
const model = defineModel<number | null>({ required: true })
</script>

<template>
  <section class="flex flex-col gap-2">
    <h3 class="field-label">{{ label }}</h3>
    <div class="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4">
      <button
        v-for="item in accounts"
        :key="item.id"
        type="button"
        class="chip shrink-0"
        :class="{ 'chip-on': model === item.id }"
        :aria-pressed="model === item.id"
        @click="model = item.id"
      >
        <AccountIcon :type="item.type" class="size-4" />
        {{ item.name }}
      </button>
      <slot />
    </div>
  </section>
</template>
