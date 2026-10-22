import type { Component } from 'vue'
import {
  CalendarCheck, CalendarClock, Coffee, Flag, Gauge, HandCoins, PartyPopper, PenLine, PieChart, PiggyBank, Repeat, Shield, ShoppingBag, Target, TrendingUp, Trophy,
} from 'lucide-vue-next'
import type { Severity } from '@/stores/advisor'

// One glyph per rule, so a glance tells what the advice is about; the colour tells how much it matters.
export const RULE_ICONS: Record<string, Component> = {
  budget_threshold: PieChart,
  category_pace: Gauge,
  overspend_forecast: TrendingUp,
  large_spend: ShoppingBag,
  small_things: Coffee,
  income_split: HandCoins,
  goal_due: CalendarClock,
  goal_leftover: Target,
  goal_pace: Flag,
  goal_reached: Trophy,
  cushion: Shield,
  savings_rate: PiggyBank,
  good_news: PartyPopper,
  forgot_to_record: PenLine,
  payment_due: CalendarCheck,
  subscription_found: Repeat,
}

/** Icon colour by severity; an alert also tints its panel, so it is the one that stands out. */
export const SEVERITY_TONES: Record<Severity, string> = {
  alert: 'text-danger',
  warn: 'text-habit',
  good: 'text-money',
  info: 'text-task',
}

export const SEVERITY_PANELS: Record<Severity, string> = {
  alert: 'bg-danger-soft',
  warn: 'bg-surface-2',
  good: 'bg-surface-2',
  info: 'bg-surface-2',
}
