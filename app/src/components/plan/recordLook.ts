import type { Component } from 'vue'
import { CircleCheck, Flame, Handshake, MoonStar, PiggyBank, PieChart, ShieldCheck, Target } from 'lucide-vue-next'
import type { JournalRecord } from '@/stores/records'

const TONES = {
  habit: { text: 'text-habit', disc: 'bg-habit-soft text-habit' },
  money: { text: 'text-money', disc: 'bg-money-soft text-money' },
  goal: { text: 'text-goal', disc: 'bg-goal-soft text-goal' },
  prayer: { text: 'text-prayer', disc: 'bg-prayer-soft text-prayer' },
} as const

/**
 * How a journal line looks: a habit's streak in the habit colour, money streaks and money facts in the
 * colour of money, goals in theirs (docs/PLAN.md, D39), the prayer streak in the prayer colour (D40).
 */
export function recordLook(record: JournalRecord): { icon: Component; text: string; disc: string } {
  const look = (icon: Component, tone: keyof typeof TONES) => ({ icon, ...TONES[tone] })
  switch (record.kind) {
    case 'streak_milestone':
    case 'streak_record':
      if (record.subject === 'prayer') return look(MoonStar, 'prayer')
      return look(Flame, record.subject.startsWith('h') ? 'habit' : 'money')
    case 'goal_first':
    case 'goal_step':
      return look(Target, 'goal')
    case 'goal_done':
      return look(CircleCheck, 'goal')
    case 'debt_closed':
      return look(Handshake, 'money')
    case 'cushion':
      return look(ShieldCheck, 'money')
    case 'month_budget':
      return look(PieChart, 'money')
    case 'savings_rate':
      return look(PiggyBank, 'money')
  }
}
