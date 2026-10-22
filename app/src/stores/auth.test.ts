import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { confirmBiometric } from '@/lib/biometric'
import { MAX_PIN_ATTEMPTS, useAuth } from './auth'

vi.mock('@/lib/biometric', () => ({ biometricAvailable: vi.fn(async () => true), confirmBiometric: vi.fn(async () => true) }))

const user = { id: 1, name: 'Elyor', telegram_username: 'elyor', locale: 'ru', timezone: 'Asia/Tashkent', base_currency: 'UZS', settings: {} }

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('auth store', () => {
  let fetchMock: ReturnType<typeof vi.fn>

  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.useFakeTimers()
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
  })

  it('keeps polling until the bot confirms, then stores the session', async () => {
    fetchMock
      .mockResolvedValueOnce(json({ id: 'req1', secret: 's3cret', link: 'https://t.me/bot?start=login_x', expires_at: new Date(Date.now() + 300_000).toISOString() }, 201))
      .mockResolvedValueOnce(json({ status: 'pending' }))
      .mockResolvedValueOnce(json({ status: 'approved', token: '1|abc', user }))

    const auth = useAuth()
    await auth.startLogin()
    expect(auth.loginState).toBe('waiting')
    expect(auth.loginLink).toBe('https://t.me/bot?start=login_x')

    await vi.advanceTimersByTimeAsync(2000)
    expect(auth.signedIn).toBe(false)
    await vi.advanceTimersByTimeAsync(2000)

    expect(auth.signedIn).toBe(true)
    expect(auth.user?.name).toBe('Elyor')
    expect(auth.loginState).toBe('idle')
    expect(JSON.parse(localStorage.getItem('kompas.token')!)).toBe('1|abc')

    const statusCall = fetchMock.mock.calls[1]
    expect(statusCall[0]).toBe('/api/auth/telegram/status/req1')
    expect((statusCall[1].headers as Headers).get('X-Login-Secret')).toBe('s3cret')
  })

  it('stops when the sign-in is declined in the bot', async () => {
    fetchMock
      .mockResolvedValueOnce(json({ id: 'req1', secret: 's', link: 'https://t.me/bot', expires_at: new Date(Date.now() + 300_000).toISOString() }, 201))
      .mockResolvedValueOnce(json({ status: 'denied' }))

    const auth = useAuth()
    await auth.startLogin()
    await vi.advanceTimersByTimeAsync(2000)

    expect(auth.loginState).toBe('denied')
    expect(auth.signedIn).toBe(false)
    await vi.advanceTimersByTimeAsync(10_000)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('signs the device out after too many wrong PINs', async () => {
    vi.useRealTimers()
    localStorage.setItem('kompas.token', JSON.stringify('1|abc'))
    localStorage.setItem('kompas.user', JSON.stringify(user))
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    const auth = useAuth()
    await auth.setPin('2580')
    auth.unlocked = false

    expect(await auth.unlock('2580')).toBe(true)
    for (let i = 0; i < MAX_PIN_ATTEMPTS - 1; i++) {
      expect(await auth.unlock('0000')).toBe(false)
    }
    expect(auth.signedIn).toBe(true)
    expect(auth.pinAttemptsLeft).toBe(1)

    await auth.unlock('0000')

    expect(auth.signedIn).toBe(false)
    expect(auth.signedOutReason).toBe('pin')
    expect(localStorage.getItem('kompas.token')).toBeNull()
    expect(fetchMock).toHaveBeenCalledWith('/api/auth/logout', expect.objectContaining({ method: 'POST' }))
  })

  it('unlocks with a fingerprint only when it is switched on, and forgets it on sign-out', async () => {
    vi.useRealTimers()
    localStorage.setItem('kompas.token', JSON.stringify('1|abc'))
    const prompt = { title: 'Вход', cancel: 'PIN' }

    const auth = useAuth()
    await auth.setPin('2580')
    auth.unlocked = false

    expect(await auth.unlockWithBiometric(prompt)).toBe(false)
    expect(confirmBiometric).not.toHaveBeenCalled()

    auth.setBiometric(true)
    await auth.unlock('0000')
    expect(auth.pinAttemptsLeft).toBe(MAX_PIN_ATTEMPTS - 1)

    vi.mocked(confirmBiometric).mockResolvedValueOnce(false)
    expect(await auth.unlockWithBiometric(prompt)).toBe(false)
    expect(auth.unlocked).toBe(false)

    expect(await auth.unlockWithBiometric(prompt)).toBe(true)
    expect(auth.unlocked).toBe(true)
    expect(auth.pinAttemptsLeft).toBe(MAX_PIN_ATTEMPTS)
    expect(localStorage.getItem('kompas.biometric')).toBe('true')

    auth.forget()
    expect(auth.biometric).toBe(false)
    expect(localStorage.getItem('kompas.biometric')).toBeNull()
  })
})
