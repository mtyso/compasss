<script setup lang="ts">
import { Columns2, Landmark, PiggyBank } from 'lucide-vue-next'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiRow from '@/components/ui/UiRow.vue'

const links = [
  { name: 'calc-compare', key: 'compare', icon: Columns2 },
  { name: 'calc-loan', key: 'loan', icon: Landmark },
  { name: 'calc-deposit', key: 'deposit', icon: PiggyBank },
] as const
</script>

<template>
  <div class="page">
    <ScreenHeader :title="$t('calc.title')" back="/more" />
    <p class="-mt-3 text-sm text-ink-2">{{ $t('calc.intro') }}</p>

    <div class="group">
      <UiRow v-for="link in links" :key="link.name" :to="{ name: link.name }" :title="$t(`calc.${link.key}.title`)" :subtitle="$t(`calc.${link.key}.hint`)">
        <template #leading>
          <span class="grid size-10 place-items-center rounded-full bg-surface-2 text-ink">
            <component :is="link.icon" class="size-5" :stroke-width="1.9" />
          </span>
        </template>
      </UiRow>
    </div>
  </div>
</template>
