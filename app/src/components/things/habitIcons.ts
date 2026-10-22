import {
  Apple, Bed, Bike, BookOpen, Brain, CigaretteOff, Coffee, Droplet, Dumbbell, Footprints, Heart, Languages, Leaf,
  Moon, Music, Pencil, PiggyBank, Pill, Smartphone, Sparkles, Sun, Target, Timer, Utensils,
} from 'lucide-vue-next'
import type { Component } from 'vue'

/** Icons a habit can wear, by the name stored on the server. */
export const HABIT_ICONS: Record<string, Component> = {
  target: Target, droplet: Droplet, dumbbell: Dumbbell, footprints: Footprints, bike: Bike, 'book-open': BookOpen,
  brain: Brain, moon: Moon, bed: Bed, sun: Sun, apple: Apple, utensils: Utensils, pill: Pill, heart: Heart,
  leaf: Leaf, coffee: Coffee, 'cigarette-off': CigaretteOff, smartphone: Smartphone, languages: Languages,
  pencil: Pencil, music: Music, timer: Timer, 'piggy-bank': PiggyBank, sparkles: Sparkles,
}

export function habitIcon(name: string | null): Component {
  return (name && HABIT_ICONS[name]) || Target
}
