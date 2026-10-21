<script setup lang="ts">
import { computed } from 'vue'
import {
  ArrowLeftRight, Award, Baby, Banknote, Book, Briefcase, Car, CircleEllipsis, Coffee, Dumbbell, Gift,
  GraduationCap, HandHeart, HeartPulse, House, Landmark, PawPrint, Percent, Plane, Shirt, ShoppingBasket,
  Smartphone, Sparkles, Tag, TreePalm, Utensils, Wrench,
} from 'lucide-vue-next'
import { CATEGORY_COLORS } from '@/lib/palette'

const props = withDefaults(defineProps<{ icon?: string | null; color?: string | null; size?: 'sm' | 'md' | 'lg' }>(), {
  icon: null,
  color: null,
  size: 'md',
})

const ICONS = {
  'shopping-basket': ShoppingBasket, utensils: Utensils, car: Car, house: House, smartphone: Smartphone,
  'heart-pulse': HeartPulse, shirt: Shirt, sparkles: Sparkles, gift: Gift, 'tree-palm': TreePalm,
  'graduation-cap': GraduationCap, landmark: Landmark, 'hand-heart': HandHeart, 'circle-ellipsis': CircleEllipsis,
  banknote: Banknote, briefcase: Briefcase, award: Award, percent: Percent, tag: Tag, baby: Baby,
  'paw-print': PawPrint, dumbbell: Dumbbell, plane: Plane, coffee: Coffee, book: Book, wrench: Wrench,
  transfer: ArrowLeftRight,
} as const

const component = computed(() => ICONS[(props.icon ?? 'circle-ellipsis') as keyof typeof ICONS] ?? CircleEllipsis)
const hex = computed(() => CATEGORY_COLORS[props.color ?? 'gray'] ?? CATEGORY_COLORS.gray)
const box = computed(() => ({ sm: 'size-9 rounded-xl', md: 'size-10 rounded-[0.875rem]', lg: 'size-12 rounded-2xl' })[props.size])
const glyph = computed(() => ({ sm: 'size-[18px]', md: 'size-5', lg: 'size-6' })[props.size])
</script>

<template>
  <span class="grid shrink-0 place-items-center" :class="box" :style="{ backgroundColor: `color-mix(in srgb, ${hex} 14%, transparent)`, color: hex }">
    <component :is="component" :class="glyph" :stroke-width="1.9" />
  </span>
</template>
