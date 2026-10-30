import { describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import { formatLeft, minutesUntil, tapAction, windowShare } from './prayer'
import { compassPoint, headingFrom, kaabaDistance, qiblaBearing, sunOverQibla, sunPosition, telegramHeading, turnTo } from './qibla'
import { isoTime } from './dates'
import type { Translate } from './tasks'

const t = i18n.global.t as unknown as Translate
const TASHKENT = { lat: 41.2995, lon: 69.2401 }

describe('tapAction', () => {
  it('marks the prayer whose time is on, asks about the others, leaves the ones ahead alone', () => {
    expect(tapAction('now')).toBe('mark')
    expect(tapAction('missed')).toBe('choose')
    expect(tapAction('done')).toBe('choose')
    expect(tapAction('qaza')).toBe('choose')
    expect(tapAction('skipped')).toBe('choose')
    expect(tapAction('later')).toBe('none')
  })
})

describe('time left', () => {
  const now = new Date('2026-10-30T16:00:00+05:00')

  it('counts minutes to a time in Tashkent and says them in words', () => {
    expect(minutesUntil('2026-10-30', '17:25', now)).toBe(85)
    expect(minutesUntil('2026-10-30', '15:39', now)).toBe(-21)
    expect(formatLeft(85, t)).toBe('1 ч 25 мин')
    expect(formatLeft(45, t)).toBe('45 мин')
    expect(formatLeft(120, t)).toBe('2 ч')
    expect(formatLeft(-3, t)).toBe('0 мин')
  })

  it('measures how much of a prayer\'s time is gone, Isha through the night', () => {
    expect(windowShare('2026-10-30', '15:39', '17:25', now)).toBeCloseTo(21 / 106, 3)
    expect(windowShare('2026-10-30', '18:41', '05:33', new Date('2026-10-31T00:07:00+05:00'))).toBeCloseTo(0.5, 2)
    expect(windowShare('2026-10-30', '17:25', '18:41', now)).toBe(0)
  })
})

describe('qibla', () => {
  it('points from Tashkent to the south-west, about 240°, some 3 500 km away', () => {
    expect(qiblaBearing(TASHKENT.lat, TASHKENT.lon)).toBeCloseTo(240.3, 1)
    expect(qiblaBearing(42.4531, 59.6103)).toBeCloseTo(224.4, 1)
    expect(Math.round(kaabaDistance(TASHKENT.lat, TASHKENT.lon) / 10) * 10).toBe(3530)
    expect(compassPoint(240.3)).toBe('sw')
    expect(compassPoint(359)).toBe('n')
  })

  it('reads a phone\'s heading against true north, iOS and Android alike, and the turn to make', () => {
    expect(headingFrom({ alpha: 90 }, 5.7)).toBeCloseTo(275.7, 5)
    expect(headingFrom({ alpha: 10, webkitCompassHeading: 100 }, 5.7)).toBeCloseTo(105.7, 5)
    expect(headingFrom({ alpha: 0 }, 5.7, 90)).toBeCloseTo(95.7, 5)
    expect(headingFrom({ alpha: null }, 5.7)).toBeNull()
    // Facing magnetic east in Telegram: Android sends −π/2, iOS +π/2.
    expect(telegramHeading(-Math.PI / 2, 'android', 5.7)).toBeCloseTo(95.7, 5)
    expect(telegramHeading(Math.PI / 2, 'ios', 5.7)).toBeCloseTo(95.7, 5)
    expect(telegramHeading(-Math.PI / 2, 'ios', 5.7)).toBeCloseTo(275.7, 5)
    expect(turnTo(350, 10)).toBe(20)
    expect(turnTo(10, 350)).toBe(-20)
    expect(turnTo(240, 60)).toBe(-180)
  })

  it('finds when the sun stands over the Qibla, and knows the winter days it sets first', () => {
    const noon = sunPosition(new Date('2026-10-30T12:07:00+05:00'), TASHKENT.lat, TASHKENT.lon)
    expect(noon.azimuth).toBeCloseTo(180, 0)
    expect(noon.altitude).toBeCloseTo(34.9, 0)

    const bearing = qiblaBearing(TASHKENT.lat, TASHKENT.lon)
    const at = sunOverQibla('2026-10-30', TASHKENT.lat, TASHKENT.lon, bearing)
    expect(at && isoTime(at)).toBe('16:13')
    expect(Math.abs(turnTo(sunPosition(at!, TASHKENT.lat, TASHKENT.lon).azimuth, bearing))).toBeLessThan(0.5)
    expect(sunOverQibla('2026-12-21', TASHKENT.lat, TASHKENT.lon, bearing)).toBeNull()
  })
})
