import { describe, expect, it } from 'vitest'
import { againstUsual, movers, type ReportCategory } from './report'

const row = (category_id: number, total_uzs: number, usual_uzs: number | null, one_off_uzs = 0): ReportCategory => ({
  category_id,
  total_uzs,
  one_off_uzs,
  usual_uzs,
  limit_uzs: null,
})

describe('movers', () => {
  it('picks categories clearly above or below the usual, biggest first, one-offs left out', () => {
    const { up, down } = movers([
      row(1, 800_000, 500_000), // cafe +300 000
      row(2, 900_000, 1_100_000), // groceries −200 000
      row(3, 1_500_000, 0, 1_500_000), // a coat bought once: not a change in habits
      row(4, 530_000, 500_000), // +30 000 is noise
      row(5, 2_000_000, 1_500_000), // transport +500 000
      row(6, 100_000, null), // nothing to compare with yet
    ])
    expect(up.map((m) => [m.category_id, m.diff])).toEqual([
      [5, 500_000],
      [1, 300_000],
    ])
    expect(down).toEqual([{ category_id: 2, regular: 900_000, usual: 1_100_000, diff: -200_000 }])
  })

  it('compares a month still going with the usual level up to today', () => {
    // Half the month gone: 500 000 usual by now, 400 000 spent
    expect(movers([row(1, 400_000, 1_000_000)], 0.5).down).toEqual([{ category_id: 1, regular: 400_000, usual: 500_000, diff: -100_000 }])
  })

  it('needs a change of at least 15% of the usual for a big category', () => {
    expect(movers([row(1, 10_400_000, 10_000_000)]).up).toEqual([])
    expect(movers([row(1, 11_600_000, 10_000_000)]).up).toHaveLength(1)
  })
})

describe('againstUsual', () => {
  it('compares regular spending with the usual level in percent', () => {
    expect(againstUsual({ expense_uzs: 3_200_000, one_off_uzs: 1_500_000, usual_uzs: 1_700_000, pace: 1 })).toBe(0)
    expect(againstUsual({ expense_uzs: 1_496_000, one_off_uzs: 0, usual_uzs: 1_700_000, pace: 1 })).toBe(-12)
    expect(againstUsual({ expense_uzs: 1_020_000, one_off_uzs: 0, usual_uzs: 1_700_000, pace: 0.5 })).toBe(20)
    expect(againstUsual({ expense_uzs: 1_000_000, one_off_uzs: 0, usual_uzs: null, pace: 1 })).toBeNull()
  })
})
