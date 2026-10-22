import { beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({ native: true, broken: false, saved: new Map<string, string>() }))

vi.mock('./native', () => ({
  get isNative() {
    return state.native
  },
}))

vi.mock('@capgo/capacitor-native-biometric', () => ({
  NativeBiometric: {
    isDataSaved: vi.fn(async ({ key }: { key: string }) => {
      if (state.broken) throw new Error('keystore')
      return { isSaved: state.saved.has(key) }
    }),
    getData: vi.fn(async ({ key }: { key: string }) => ({ value: state.saved.get(key)! })),
    setData: vi.fn(async ({ key, value }: { key: string; value: string }) => void state.saved.set(key, value)),
    deleteData: vi.fn(async ({ key }: { key: string }) => void state.saved.delete(key)),
  },
}))

async function freshModule() {
  vi.resetModules()
  return import('./secure')
}

describe('secure storage', () => {
  beforeEach(() => {
    localStorage.clear()
    state.native = true
    state.broken = false
    state.saved.clear()
  })

  it('reads what the keystore holds before the app starts and writes through to it', async () => {
    state.saved.set('kompas.token', JSON.stringify('1|abc'))
    const secure = await freshModule()

    expect(secure.readSecret('kompas.token')).toBeNull()
    await secure.loadSecrets(['kompas.token', 'kompas.pin'])
    expect(secure.readSecret('kompas.token')).toBe('1|abc')
    expect(secure.readSecret('kompas.pin')).toBeNull()

    secure.writeSecret('kompas.pin', { salt: 's', hash: 'h', iterations: 1 })
    expect(secure.readSecret('kompas.pin')).toEqual({ salt: 's', hash: 'h', iterations: 1 })
    await vi.waitFor(() => expect(state.saved.has('kompas.pin')).toBe(true))
    expect(localStorage.getItem('kompas.pin')).toBeNull()

    secure.writeSecret('kompas.token', null)
    expect(secure.readSecret('kompas.token')).toBeNull()
    await vi.waitFor(() => expect(state.saved.has('kompas.token')).toBe(false))
  })

  it('moves a token saved in localStorage by an older build into the keystore', async () => {
    localStorage.setItem('kompas.token', JSON.stringify('1|old'))
    const secure = await freshModule()

    await secure.loadSecrets(['kompas.token'])

    expect(secure.readSecret('kompas.token')).toBe('1|old')
    expect(localStorage.getItem('kompas.token')).toBeNull()
    await vi.waitFor(() => expect(state.saved.get('kompas.token')).toBe(JSON.stringify('1|old')))
  })

  it('starts signed out when the keystore refuses, without touching localStorage', async () => {
    state.broken = true
    localStorage.setItem('kompas.token', JSON.stringify('1|old'))
    const secure = await freshModule()

    await secure.loadSecrets(['kompas.token'])

    expect(secure.readSecret('kompas.token')).toBeNull()
    expect(localStorage.getItem('kompas.token')).toBe(JSON.stringify('1|old'))
  })

  it('keeps using localStorage in the browser', async () => {
    state.native = false
    const secure = await freshModule()

    await secure.loadSecrets(['kompas.token'])
    secure.writeSecret('kompas.token', '1|web')

    expect(localStorage.getItem('kompas.token')).toBe(JSON.stringify('1|web'))
    expect(secure.readSecret('kompas.token')).toBe('1|web')
    expect(state.saved.size).toBe(0)
  })
})
