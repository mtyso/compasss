import { Briefcase, Car, Dumbbell, GraduationCap, Moon, Sofa, Sparkles, Users, Utensils } from 'lucide-vue-next'
import type { Component } from 'vue'
import { CATEGORY_COLORS } from '@/lib/palette'
import type { RoutineKind } from '@/stores/calendar'

export interface RoutineLook {
  icon: Component
  /** A colour token of CATEGORY_COLORS. */
  color: string
}

/** How each kind of routine block looks: its icon and colour, the same on the calendar, the routine page and «Сегодня». */
export const ROUTINE_LOOK: Record<RoutineKind, RoutineLook> = {
  sleep: { icon: Moon, color: 'indigo' },
  work: { icon: Briefcase, color: 'slate' },
  study: { icon: GraduationCap, color: 'cyan' },
  sport: { icon: Dumbbell, color: 'green' },
  meal: { icon: Utensils, color: 'orange' },
  road: { icon: Car, color: 'gray' },
  rest: { icon: Sofa, color: 'teal' },
  family: { icon: Users, color: 'rose' },
  other: { icon: Sparkles, color: 'purple' },
}

/** The look of a kind; an unknown one (a newer server) looks like «Другое». */
export function routineLook(kind: string): RoutineLook {
  return ROUTINE_LOOK[kind as RoutineKind] ?? ROUTINE_LOOK.other
}

/** The kind's colour as a CSS value. */
export function routineColor(kind: string): string {
  return CATEGORY_COLORS[routineLook(kind).color]
}
