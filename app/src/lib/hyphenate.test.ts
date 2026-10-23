import { describe, expect, it } from 'vitest'
import { softHyphenate } from './hyphenate'

const breaks = (text: string): string[] => softHyphenate(text).split('­')

describe('softHyphenate', () => {
  it('leaves short words alone', () => {
    expect(softHyphenate('Кафе и рестораны')).toBe('Кафе и рестораны')
  })

  it('allows breaks inside long words only', () => {
    const result = softHyphenate('Благотворительность')
    expect(result.replaceAll('­', '')).toBe('Благотворительность')
    expect(breaks('Благотворительность')).toEqual(['Бла', 'го', 'тво', 'ри', 'тель', 'ность'])
  })

  it('keeps a consonant with its soft sign', () => {
    expect(breaks('Благотворительность').join('-')).not.toContain('те-ль')
  })

  it("splits short words into two-letter ends for a phone's week columns", () => {
    const split = (word: string) => softHyphenate(word, 6, 2).split('­')
    expect(split('Встреча')).toEqual(['Встре', 'ча'])
    expect(split('друзьями')).toEqual(['друзья', 'ми'])
    expect(split('Обед')).toEqual(['Обед'])
  })

  it('handles Uzbek words', () => {
    const parts = breaks('Xayriya-ehsonlar')
    expect(parts.join('')).toBe('Xayriya-ehsonlar')
  })
})
