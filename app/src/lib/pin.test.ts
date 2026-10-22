// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { createPinRecord, verifyPin } from './pin'

describe('PIN', () => {
  it('accepts the PIN it was created from and nothing else', async () => {
    const record = await createPinRecord('2580')

    expect(record.hash).not.toContain('2580')
    expect(await verifyPin('2580', record)).toBe(true)
    expect(await verifyPin('2581', record)).toBe(false)
  })

  it('salts every record', async () => {
    const [a, b] = await Promise.all([createPinRecord('1111'), createPinRecord('1111')])

    expect(a.salt).not.toBe(b.salt)
    expect(a.hash).not.toBe(b.hash)
  })
})
