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

export const SEVERITY_TONES: Record<Severity, string> = {
  alert: 'bg-danger-soft text-danger',
  warn: 'bg-habit-soft text-habit',
  good: 'bg-money-soft text-money',
  info: 'bg-task-soft text-task',
}
