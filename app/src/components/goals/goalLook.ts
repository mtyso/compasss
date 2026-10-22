import type { Component } from 'vue'
import {
  BookOpen, Car, Dumbbell, Flag, Gift, GraduationCap, Heart, House, Landmark, Laptop, Mountain, PiggyBank, Plane, Shield,
  ShoppingBag, Smartphone, Sofa, Trophy,
} from 'lucide-vue-next'
import type { Goal, GoalType } from '@/stores/goals'

/** Icons a goal can wear; the name is what the server keeps. */
export const GOAL_ICONS: Record<string, Component> = {
  'piggy-bank': PiggyBank,
  car: Car,
  house: House,
  plane: Plane,
  smartphone: Smartphone,
  laptop: Laptop,
  sofa: Sofa,
  'graduation-cap': GraduationCap,
  gift: Gift,
  heart: Heart,
  shield: Shield,
  landmark: Landmark,
  'shopping-bag': ShoppingBag,
  dumbbell: Dumbbell,
  'book-open': BookOpen,
  mountain: Mountain,
  trophy: Trophy,
  flag: Flag,
}

export const TYPE_ICONS: Record<GoalType, string> = {
  save: 'piggy-bank',
  buy: 'shopping-bag',
  debt: 'landmark',
  other: 'flag',
}

export function goalIcon(goal: Pick<Goal, 'icon' | 'type'>): Component {
  return GOAL_ICONS[goal.icon ?? ''] ?? GOAL_ICONS[TYPE_ICONS[goal.type]]!
}
