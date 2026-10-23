<script setup lang="ts">
import { ref, watch } from 'vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { haptics } from '@/lib/haptics'
import MiniMonth from './MiniMonth.vue'

/** «Выбрать дату»: a month to jump to any day, from the period title; it opens on the day on screen. */
const props = defineProps<{ selected: string; today: string; marked?: ReadonlySet<string> }>()
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ pick: [day: string] }>()

const month = ref(props.selected.slice(0, 7))
watch(open, (value) => {
  if (value) month.value = props.selected.slice(0, 7)
})

function pick(day: string): void {
  haptics.selection()
  emit('pick', day)
  open.value = false
}
</script>

<template>
  <UiSheet v-model:open="open" :title="$t('calendar.pickDate')">
    <MiniMonth v-model:month="month" :selected="selected" :today="today" :marked="marked" class="pb-1" @pick="pick" />
    <template #footer>
      <UiButton variant="secondary" block @click="pick(today)">{{ $t('calendar.today') }}</UiButton>
    </template>
  </UiSheet>
</template>
