import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { i18n } from '@/i18n'
import { weekDays } from '@/lib/calendar'
import type { CalendarItem, EventItem, HabitItem, Layer, PaymentItem, RoutineOccurrence, TaskItem } from '@/stores/calendar'
import { useHabits, type Habit } from '@/stores/habits'
import DayView from './DayView.vue'
import ListView from './ListView.vue'
import MonthView from './MonthView.vue'
import WeekView from './WeekView.vue'

// The views mounted with a realistic feed: what shows where, and what a tap asks the page to do.

const today = '2026-10-23' // a Friday
const all = new Set<Layer>(['events', 'routine', 'tasks', 'payments', 'income', 'goals', 'debts', 'habits'])

const meeting: EventItem = {
  type: 'event', key: `e1:${today}`, date: today, title: 'Встреча', event_id: 1, series_id: null, end_date: today,
  start: '10:00', end: '11:00', all_day: false, color: 'teal', repeating: false, remind_before: null, notes: 'Офис',
}
const trip: EventItem = { ...meeting, key: 'e2:2026-10-22', event_id: 2, title: 'Поездка', date: '2026-10-22', end_date: '2026-10-24', start: null, end: null, all_day: true }
const call: TaskItem = {
  type: 'task', key: `t3:${today}`, date: today, title: 'Позвонить', task_id: 3, start: '15:00', priority: 0, done: false,
  overdue: false, projected: false, repeating: false,
}
const internet: PaymentItem = {
  type: 'payment', key: `p4:${today}`, date: today, title: 'Интернет', payment_id: 4, amount: 150_000, currency: 'UZS',
  overdue: false, projected: false, paid: false,
}
const water: HabitItem = {
  type: 'habit', key: `h5:${today}`, date: today, title: 'Вода', habit_id: 5, icon: 'droplet', color: 'blue', habit_type: 'count',
  value: 3, target: 8, unit: null, start: null, state: 'open',
}
const sport: HabitItem = { ...water, key: `h6:${today}`, habit_id: 6, title: 'Спорт', habit_type: 'check', target: 1, value: 0, start: '19:00' }

const sleep: RoutineOccurrence = {
  block_id: 7, key: 'r7:2026-10-22', date: '2026-10-22', end_date: today, start: '23:00', end: '07:00', title: 'Сон',
  kind: 'sleep', habit_id: null, remind: false,
}
const gym: RoutineOccurrence = { ...sleep, block_id: 8, key: `r8:${today}`, date: today, end_date: today, start: '19:00', end: '20:00', title: 'Зал', kind: 'sport', habit_id: 6 }

function habit(id: number, name: string, type: Habit['type']): Habit {
  return {
    id, name, type, target: type === 'check' ? 1 : 8, unit: null, schedule: { days: [1, 2, 3, 4, 5, 6, 7] }, color: 'blue', icon: null,
    sort: 0, started_on: '2026-01-01', archived: false, today: { value: 0, state: 'open' }, week_count: null,
    streak: { current: 0, best: 0, unit: 'day' }, days: [], goal_id: null, saves_uzs: null,
  }
}

let pinia: Pinia

// jsdom has no media queries; the settings store asks for the system theme.
window.matchMedia ??= ((query: string) =>
  ({ matches: false, media: query, addEventListener() {}, removeEventListener() {} }) as unknown as MediaQueryList) as typeof window.matchMedia

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
  useHabits().habits = [habit(5, 'Вода', 'count'), habit(6, 'Спорт', 'check')]
})

const plugins = () => ({ global: { plugins: [pinia, i18n] } })
const items: CalendarItem[] = [meeting, trip, call, internet, water, sport]

describe('DayView', () => {
  const props = { day: today, today, nowMin: 600, items, routine: [sleep, gym], layers: all, wide: false, skeleton: false }

  it('puts the routine on the left, timed things on the right and the rest in the all-day area', () => {
    const view = mount(DayView, { props, ...plugins() })
    const text = view.text()
    expect(text).toContain('Сон')
    expect(text).toContain('Зал')
    expect(text).toContain('Встреча')
    expect(text).toContain('Позвонить')
    expect(text).toContain('Поездка')
    expect(text).toContain('Интернет')
    // The gym block (56px) carries the sport habit; only water is left for the chip.
    expect(text).toContain('Привычки 0/1')
    expect(view.find('[aria-label="Отметить «Спорт»"]').exists()).toBe(true)
  })

  it('shows a habit tied to a routine block in the things lane once the routine is hidden', () => {
    const layers = new Set(all)
    layers.delete('routine')
    const view = mount(DayView, { props: { ...props, routine: [], layers }, ...plugins() })
    expect(view.find('[aria-label="Спорт, 19:00"]').exists()).toBe(true)
    expect(view.text()).toContain('Привычки 0/1')
  })

  it('asks for the sheet, a new event and a log', async () => {
    const view = mount(DayView, { props, ...plugins() })
    await view.find('[aria-label="Встреча, 10:00–11:00"]').trigger('click')
    expect(view.emitted('open')?.[0]).toEqual([meeting])

    await view.find('[aria-label="Сон, 23:00–07:00"]').trigger('click')
    expect(view.emitted('routine')?.[0]).toEqual([sleep])

    await view.find('[aria-label="Отметить «Спорт»"]').trigger('click')
    expect(view.emitted('habitTap')?.[0]).toEqual([sport])
  })

  it('ticks a task at its usual half hour once the lane is wide enough', async () => {
    // The lane's width comes from a ResizeObserver, which jsdom does not have.
    const original = globalThis.ResizeObserver
    globalThis.ResizeObserver = class {
      constructor(private readonly report: ResizeObserverCallback) {}
      observe(): void {
        this.report([{ contentRect: { width: 300 } } as ResizeObserverEntry], this as unknown as ResizeObserver)
      }
      unobserve(): void {}
      disconnect(): void {}
    }
    try {
      const view = mount(DayView, { props, ...plugins() })
      await view.vm.$nextTick()
      const check = view.find('[role="checkbox"][aria-label="Отметить «Позвонить»"]')
      expect(check.exists()).toBe(true)
      await check.trigger('click')
      expect(view.emitted('toggleTask')?.[0]).toEqual([call])
      expect(view.emitted('open')).toBeUndefined()
    } finally {
      globalThis.ResizeObserver = original
    }
  })

  it('folds a phone’s all-day chips after two rows', () => {
    const many = Array.from({ length: 6 }, (_, i): PaymentItem => ({ ...internet, key: `p${10 + i}:${today}`, payment_id: 10 + i, title: `Платёж ${i}` }))
    const view = mount(DayView, { props: { ...props, items: many }, ...plugins() })
    expect(view.text()).toContain('ещё 3')
    expect(view.text()).not.toContain('Платёж 3')
  })
})

describe('WeekView', () => {
  it('lays the week out with its bars and blocks, without habits', async () => {
    const view = mount(WeekView, {
      props: { days: weekDays(today), today, nowMin: 600, items, routine: [sleep, gym], layers: all, wide: true, skeleton: false },
      ...plugins(),
    })
    const text = view.text()
    expect(text).toContain('Поездка')
    expect(text).toContain('Встреча')
    expect(text).not.toContain('Вода')
    await view.find('[aria-label="Пт, 23 октября"]').trigger('click')
    expect(view.emitted('pickDay')?.[0]).toEqual([today])
  })
})

describe('MonthView', () => {
  it('fills the cells and opens a day', async () => {
    const view = mount(MonthView, { props: { anchor: today, today, items, layers: all, wide: true, skeleton: false }, ...plugins() })
    expect(view.findAll('.cell')).toHaveLength(35)
    expect(view.text()).toContain('Встреча')
    expect(view.text()).not.toContain('Вода')
    await view.find('[aria-label="Пт, 23 октября"]').trigger('click')
    expect(view.emitted('pickDay')?.[0]).toEqual([today])
  })
})

describe('ListView', () => {
  it('lists only the days that have something, the all-day things first', () => {
    const view = mount(ListView, { props: { from: today, today, items, layers: all, skeleton: false }, ...plugins() })
    const headings = view.findAll('h2').map((h) => h.text())
    expect(headings).toEqual(['Сегодня', 'Завтра'])
    const rows = view.findAll('section')[0]!.findAll('button').map((b) => b.text())
    expect(rows[0]).toContain('Интернет')
    expect(rows.at(-1)).toContain('Позвонить')
    expect(view.text()).not.toContain('Вода')
  })

  it('says so when two weeks are empty', () => {
    const view = mount(ListView, { props: { from: today, today, items: [], layers: all, skeleton: false }, ...plugins() })
    expect(view.text()).toContain('В ближайшие две недели ничего нет.')
  })
})
