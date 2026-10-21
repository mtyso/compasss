// Soft hyphens for long words in narrow places (category tiles), where the browser may have no
// hyphenation dictionary for Russian or Uzbek. A break is allowed after a vowel followed by a
// consonant, or after ь/ъ/й followed by a consonant, keeping at least three letters and a vowel on
// each side. A consonant that carries a soft or hard sign stays with it («тель-ность», not «те-льность»).
// The browser shows a hyphen only if it actually breaks there.

const VOWELS = /[аеёиоуыэюяaeiou]/i
const SIGNS = /[ьъй]/i

export function softHyphenate(text: string, minLength = 11): string {
  return text.replace(/[\p{L}ʻ']+/gu, (word) => {
    if (word.length < minLength) return word
    let out = ''
    for (let i = 0; i < word.length; i++) {
      out += word[i]
      const left = i + 1
      const here = word[i]!
      const next = word[i + 1] ?? ''
      const after = word[i + 2] ?? ''
      const breakable =
        (VOWELS.test(here) || SIGNS.test(here)) && /\p{L}/u.test(next) && !VOWELS.test(next) && !SIGNS.test(next) && !SIGNS.test(after)
      if (breakable && left >= 3 && word.length - left >= 3 && VOWELS.test(word.slice(0, left)) && VOWELS.test(word.slice(left))) {
        out += '­'
      }
    }
    return out
  })
}
