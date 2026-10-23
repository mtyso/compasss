import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useCalendar, type CalendarEvent, type CalendarFeed } from './calendar'

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function feedOf(url: string, version: string, series: Record<string, CalendarEvent> = {}): CalendarFeed {
  const query = new URL(url, 'http://app').searchParams
  return { from: query.get('from')!, to: query.get('to')!, today: '2026-10-23', now: version, items: [], routine: [], series }
}

const series: CalendarEvent = {
  id: 5, series_id: null, series_date: null, title: 'Йога', notes: null, starts_on: '2026-10-01', ends_on: '2026-10-01',
  start_time: '08:00', end_time: '09:00', all_day: false, color: 'green', repeat: { unit: 'day', every: 1 },
  repeat_until: '2026-12-31', skip_dates: [], remind_before: null,
}

/** Calendar requests wait until the test answers them; everything else answers at once from `routes`. */
interface Pending {
  url: string
  answer: (version: string, extra?: Record<string, CalendarEvent>) => Promise<void>
}

describe('calendar store', () => {
  let calendarCalls: Pending[]
  let routes: Record<string, unknown>
  let fetchMock: ReturnType<typeof vi.fn>

  const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    calendarCalls = []
    routes = {}
    fetchMock = vi.fn((url: string, init: RequestInit = {}) => {
      if (url.startsWith('/api/calendar')) {
        return new Promise<Response>((resolve) => {
          calendarCalls.push({
            url,
            answer: async (version, extra) => {
              resolve(json(feedOf(url, version, extra)))
              await flush()
            },
          })
        })
      }
      const key = `${init.method ?? 'GET'} ${url}`
      return Promise.resolve(json(routes[key] ?? {}))
    })
    vi.stubGlobal('fetch', fetchMock)
  })

  it('loads a range, then shows it from the cache and refreshes it quietly', async () => {
    const calendar = useCalendar()
    void calendar.show('2026-10-19', '2026-10-25')
    expect(calendar.loading).toBe(true)
    expect(calendar.feed).toBeNull()
    await calendarCalls[0].answer('v1')
    expect(calendar.feed?.now).toBe('v1')
    expect(calendar.loading).toBe(false)

    void calendar.show('2026-10-26', '2026-11-01')
    // The new week is on its way: the old one stays on screen meanwhile.
    expect(calendar.feed?.now).toBe('v1')
    await calendarCalls[1].answer('w1')
    expect(calendar.feed?.from).toBe('2026-10-26')

    void calendar.show('2026-10-19', '2026-10-25')
    expect(calendar.feed?.now).toBe('v1')
    expect(calendar.loading).toBe(false)
    expect(calendarCalls).toHaveLength(3)
    await calendarCalls[2].answer('v2')
    expect(calendar.feed?.now).toBe('v2')
  })

  it('ignores an answer for a range no longer on screen', async () => {
    const calendar = useCalendar()
    void calendar.show('2026-10-19', '2026-10-25')
    void calendar.show('2026-10-26', '2026-11-01')
    await calendarCalls[1].answer('second')
    await calendarCalls[0].answer('first')
    expect(calendar.feed?.from).toBe('2026-10-26')
    expect(calendar.feeds.has('2026-10-19:2026-10-25')).toBe(true)
  })

  it('reloads after a load that was already running when something changed', async () => {
    const calendar = useCalendar()
    void calendar.show('2026-10-19', '2026-10-25')
    await calendarCalls[0].answer('v1')

    void calendar.show('2026-10-19', '2026-10-25')
    void calendar.refresh()
    void calendar.refresh()
    // One request at a time: the refreshes wait for the quiet reload.
    expect(calendarCalls).toHaveLength(2)
    expect(calendar.feed?.now).toBe('v1')

    // That answer may predate the change: what is on screen stays until the reload.
    await calendarCalls[1].answer('stale')
    expect(calendar.feed?.now).toBe('v1')
    expect(calendarCalls).toHaveLength(3)
    await calendarCalls[2].answer('fresh')
    expect(calendar.feed?.now).toBe('fresh')
    expect(calendarCalls).toHaveLength(3)
  })

  it('never caches an answer from before a change, even for a range left meanwhile', async () => {
    const calendar = useCalendar()
    void calendar.show('2026-10-19', '2026-10-25')
    await calendarCalls[0].answer('v1')
    void calendar.show('2026-10-19', '2026-10-25')
    void calendar.refresh()
    // Paged on before that answer came.
    void calendar.show('2026-10-26', '2026-11-01')
    expect(calendarCalls).toHaveLength(3)

    await calendarCalls[1].answer('before change')
    expect(calendar.feeds.has('2026-10-19:2026-10-25')).toBe(false)
    await calendarCalls[2].answer('w1')
    expect(calendar.feed?.now).toBe('w1')
    expect(calendarCalls).toHaveLength(3)

    // Back: nothing stale to show from the cache, a fresh request.
    void calendar.show('2026-10-19', '2026-10-25')
    expect(calendarCalls).toHaveLength(4)
    await calendarCalls[3].answer('v2')
    expect(calendar.feed?.now).toBe('v2')
  })

  it('drops prefetched answers that a change made stale', async () => {
    const calendar = useCalendar()
    void calendar.show('2026-10-19', '2026-10-25')
    await calendarCalls[0].answer('v1')
    void calendar.prefetch('2026-10-26', '2026-11-01')
    void calendar.prefetch('2026-10-26', '2026-11-01')
    expect(calendarCalls).toHaveLength(2)

    void calendar.refresh()
    expect(calendarCalls).toHaveLength(3)
    await calendarCalls[1].answer('stale')
    expect(calendar.feeds.has('2026-10-26:2026-11-01')).toBe(false)
    await calendarCalls[2].answer('v2')
    expect(calendar.feed?.now).toBe('v2')

    // A range prefetched and then shown needs no second request.
    void calendar.prefetch('2026-10-26', '2026-11-01')
    void calendar.show('2026-10-26', '2026-11-01')
    expect(calendarCalls).toHaveLength(4)
    await calendarCalls[3].answer('w1')
    expect(calendar.feed?.now).toBe('w1')
  })

  it('says when the range on screen did not load', async () => {
    fetchMock.mockImplementationOnce(() => Promise.resolve(json({ message: 'down' }, 500)))
    const calendar = useCalendar()
    await calendar.show('2026-10-19', '2026-10-25')
    expect(calendar.failed).toBe(true)
    expect(calendar.loading).toBe(false)
  })

  it('remembers the layers on this device and survives a bad stored value', () => {
    localStorage.setItem('kompas.calendarLayers', JSON.stringify('all'))
    expect([...useCalendar().layers]).toHaveLength(8)

    setActivePinia(createPinia())
    localStorage.setItem('kompas.calendarLayers', JSON.stringify(['tasks', 'nonsense', 'events']))
    const calendar = useCalendar()
    expect([...calendar.layers]).toEqual(['events', 'tasks'])

    calendar.toggleLayer('habits')
    calendar.toggleLayer('events')
    expect(calendar.layers.has('events')).toBe(false)
    expect(JSON.parse(localStorage.getItem('kompas.calendarLayers')!)).toEqual(['tasks', 'habits'])

    calendar.reset()
    expect(calendar.layers.has('habits')).toBe(true)
  })

  it('says how to undo each kind of delete', async () => {
    const calendar = useCalendar()
    routes['DELETE /api/events/5?scope=one&date=2026-10-23'] = { event: series }
    routes['DELETE /api/events/7?scope=all'] = { event: null }
    routes['GET /api/events/5'] = series
    routes['DELETE /api/events/5?scope=following&date=2026-10-23'] = { event: { ...series, repeat_until: '2026-10-22' } }

    expect(await calendar.removeEvent(5, 'one', '2026-10-23')).toEqual({ kind: 'unskip', id: 5, date: '2026-10-23' })
    expect(await calendar.removeEvent(7, 'all')).toEqual({ kind: 'restore', id: 7 })
    // The end the series had comes from the event before the cut.
    expect(await calendar.removeEvent(5, 'following', '2026-10-23')).toEqual({ kind: 'until', id: 5, repeat_until: '2026-12-31' })

    await calendar.undo({ kind: 'unskip', id: 5, date: '2026-10-23' })
    await calendar.undo({ kind: 'until', id: 5, repeat_until: null })
    await calendar.undo({ kind: 'restore', id: 7 })
    const sent = fetchMock.mock.calls.slice(-3).map(([url, init]) => [url, init.method, init.body])
    expect(sent).toEqual([
      ['/api/events/5?scope=all', 'PATCH', JSON.stringify({ unskip: '2026-10-23' })],
      ['/api/events/5?scope=all', 'PATCH', JSON.stringify({ repeat_until: null })],
      ['/api/events/7/restore', 'POST', undefined],
    ])
  })

  it('takes the series from the feed on screen when it can', async () => {
    const calendar = useCalendar()
    void calendar.show('2026-10-19', '2026-10-25')
    await calendarCalls[0].answer('v1', { 5: { ...series, repeat_until: null } })
    routes['DELETE /api/events/5?scope=following&date=2026-10-23'] = { event: { ...series, repeat_until: '2026-10-22' } }

    expect(await calendar.removeEvent(5, 'following', '2026-10-23')).toEqual({ kind: 'until', id: 5, repeat_until: null })
    expect(fetchMock.mock.calls.some(([url]) => url === '/api/events/5')).toBe(false)
  })

  it('keeps the routine blocks in time order and refreshes the feed after a change', async () => {
    const calendar = useCalendar()
    routes['GET /api/routine'] = [{ id: 1, start_time: '09:00' }, { id: 2, start_time: '23:00' }]
    routes['POST /api/routine'] = { id: 3, start_time: '13:00' }
    await calendar.ensureRoutine()
    await calendar.ensureRoutine()
    expect(fetchMock.mock.calls.filter(([url]) => url === '/api/routine')).toHaveLength(1)

    void calendar.show('2026-10-23', '2026-10-23')
    await calendarCalls[0].answer('v1')
    await calendar.saveBlock({ title: 'Обед', kind: 'meal', start_time: '13:00', end_time: '14:00', days: [1, 2, 3, 4, 5] })
    expect(calendar.blocks.map((b) => b.id)).toEqual([1, 3, 2])
    expect(calendarCalls).toHaveLength(2)
  })
})
