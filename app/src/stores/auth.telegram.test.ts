import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { setTelegramApp, type TelegramWebApp } from '@/lib/telegram'
import { useAuth } from './auth'

const user = { id: 1, name: 'Elyor', telegram_username: 'elyor', locale: 'ru', timezone: 'Asia/Tashkent', base_currency: 'UZS', settings: {} }

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('auth store inside the Telegram Mini App', () => {
  let fetchMock: ReturnType<typeof vi.fn>

  beforeEach(() => {
    localStorage.clear()
    setTelegramApp({ initData: 'auth_date=1&user=%7B%7D&hash=abc', platform: 'android' } as TelegramWebApp)
    setActivePinia(createPinia())
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => setTelegramApp(null))

  it('trades the launch data for a token and needs no PIN', async () => {
    fetchMock.mockResolvedValueOnce(json({ token: '7|tg', user }))

    const auth = useAuth()
    expect(await auth.signInWithTelegram()).toBe(true)

    expect(fetchMock).toHaveBeenCalledWith('/api/auth/telegram/webapp', expect.objectContaining({ method: 'POST' }))
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ init_data: 'auth_date=1&user=%7B%7D&hash=abc', platform: 'android' })
    expect(auth.signedIn).toBe(true)
    expect(auth.unlocked).toBe(true)
    expect(auth.pin).toBeNull()
  })

  it('shows the server’s reason when the account is not allowed', async () => {
    fetchMock.mockResolvedValueOnce(json({ message: 'Этому аккаунту Telegram вход закрыт.' }, 403))

    const auth = useAuth()
    expect(await auth.signInWithTelegram()).toBe(false)

    expect(auth.signedIn).toBe(false)
    expect(auth.loginState).toBe('error')
    expect(auth.loginError).toBe('Этому аккаунту Telegram вход закрыт.')
  })
})
