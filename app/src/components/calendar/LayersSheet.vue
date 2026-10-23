<script setup lang="ts">
import UiSheet from '@/components/ui/UiSheet.vue'
import LayersList from './LayersList.vue'

/** «Что показывать» below 80rem, where there is no side panel: the layers and «Распорядок дня». */
const open = defineModel<boolean>('open', { required: true })
</script>

<template>
  <UiSheet v-model:open="open" :title="$t('calendar.layers')">
    <LayersList class="-mt-1" @navigate="open = false" />
  </UiSheet>
</template>
