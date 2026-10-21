import { describe, expect, it } from 'vitest'
import { isoDate, isoMonth, isoTime, shiftDate, shiftMonth } from './dates'

describe('dates in Tashkent', () => {
  it('takes the Tashkent calendar day, not the device one', () => {
    // 20:30 UTC on the 21st is already 01:30 on the 22nd in Tashkent (UTC+5).
    const late = new Date('2026-10-21T20:30:00Z')
    expect(isoDate(late)).toBe('2026-10-22')
    expect(isoTime(late)).toBe('01:30')
    expect(isoMonth(new Date('2026-10-31T19:30:00Z'))).toBe('2026-11')
  })

  it('moves across month and year boundaries', () => {
    expect(shiftMonth('2026-12', 1)).toBe('2027-01')
    expect(shiftMonth('2026-01', -1)).toBe('2025-12')
    expect(shiftDate('2026-03-01', -1)).toBe('2026-02-28')
  })
})
