<script setup lang="ts">
import UiSheet from '@/components/ui/UiSheet.vue'

// Form sheet with a title: the shared ui/UiSheet with the spacing forms use.
// Kept so existing screens keep working; new code can use UiSheet directly.
defineProps<{ title: string }>()
const open = defineModel<boolean>('open', { required: true })
</script>

<template>
  <UiSheet v-model:open="open" :title="title">
    <div class="flex flex-col gap-5 pt-1">
      <slot />
    </div>
    <template v-if="$slots.footer" #footer>
      <div class="flex flex-col gap-2">
        <slot name="footer" />
      </div>
    </template>
  </UiSheet>
</template>
