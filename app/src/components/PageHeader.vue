<script setup lang="ts">
import { ChevronLeft } from 'lucide-vue-next'
import { useRouter } from 'vue-router'

const props = defineProps<{ title: string; back?: string }>()
const router = useRouter()

function goBack(): void {
  if (window.history.state?.back) router.back()
  else router.replace(props.back ?? '/more')
}
</script>

<template>
  <header class="flex items-center gap-2">
    <button type="button" class="-ml-2 grid size-9 place-items-center rounded-full active:bg-line" :aria-label="$t('common.back')" @click="goBack">
      <ChevronLeft class="size-6" />
    </button>
    <h1 class="flex-1 truncate font-display text-xl font-bold">{{ title }}</h1>
    <slot />
  </header>
</template>
