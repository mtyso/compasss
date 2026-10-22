import { describe, expect, it } from 'vitest'
import { parseAmount, toUzs } from './money'

describe('parseAmount', () => {
  it.each([
    ['32000', 32_000],
    ['32 000', 32_000],
    ['32к', 32_000],
    ['32k', 32_000],
    ['1,5м', 1_500_000],
    ['1.5m', 1_500_000],
    ['2 млн', 2_000_000],
  ])('reads %s as som', (input, expected) => {
    expect(parseAmount(input, 'UZS')).toBe(expected)
  })

  it('reads dollars into cents', () => {
    expect(parseAmount('20', 'USD')).toBe(2000)
    expect(parseAmount('12,50', 'USD')).toBe(1250)
    expect(parseAmount('0.1', 'USD')).toBe(10)
  })

  it.each(['', '0', '-5', 'abc', '1..2', '32кк'])('rejects «%s»', (input) => {
    expect(parseAmount(input, 'UZS')).toBeNull()
  })
})

describe('toUzs', () => {
  it('converts cents at the rate like the server does', () => {
    expect(toUzs(2000, 'USD', '12050.1200')).toBe(241_002)
    expect(toUzs(85_000, 'UZS', '1')).toBe(85_000)
  })
})
