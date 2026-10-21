import { describe, expect, it } from 'vitest'
import { groupDigits, groupInput } from './digits'

describe('groupDigits', () => {
  it('groups plain whole numbers by thousands', () => {
    expect(groupDigits('85000')).toBe('85 000')
    expect(groupDigits('1 2345678')).toBe('12 345 678')
    expect(groupDigits('950')).toBe('950')
    expect(groupDigits('-250000')).toBe('-250\u202f000')
  })

  it('leaves shorthand and decimals as typed', () => {
    expect(groupDigits('32к')).toBe('32к')
    expect(groupDigits('12.5')).toBe('12.5')
    expect(groupDigits('')).toBe('')
  })
})

describe('groupInput', () => {
  it('keeps the caret after the same digit', () => {
    const input = document.createElement('input')
    document.body.append(input)
    input.value = '1000'
    input.setSelectionRange(1, 1)
    input.value = '12000'
    input.setSelectionRange(2, 2)
    let text = ''
    groupInput({ target: input } as unknown as Event, (value) => (text = value))
    expect(text).toBe('12 000')
    expect(input.selectionStart).toBe(2)
  })
})
