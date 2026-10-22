import type { Component } from 'vue'
import { Coffee, Gauge, HandCoins, PartyPopper, PenLine, PieChart, PiggyBank, Shield, ShoppingBag, TrendingUp } from 'lucide-vue-next'
import type { Severity } from '@/stores/advisor'

// One glyph per rule, so a glance tells what the advice is about; the colour tells how much it matters.
export const RULE_ICONS: Record<string, Component> = {
  budget_threshold: PieChart,
  category_pace: Gauge,
  overspend_forecast: TrendingUp,
  large_spend: ShoppingBag,
  small_things: Coffee,
  income_split: HandCoins,
  cushion: Shield,
  savings_rate: PiggyBank,
  good_news: PartyPopper,
  forgot_to_record: PenLine,
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
