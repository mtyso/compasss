import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { pushGranted, registerPush, unregisterPush } from '@/lib/push'
import { useAuth } from './auth'
import { usePush } from './push'

vi.mock('@/lib/push', () => ({
  pushSupported: true,
  pushGranted: vi.fn(async () => true),
  registerPush: vi.fn(async () => 'fcm-token-1'),
  unregisterPush: vi.fn(async () => {}),
}))

const user = { id: 1, name: 'Elyor', telegram_username: 'elyor', locale: 'ru', timezone: 'Asia/Tashkent', base_currency: 'UZS', settings: {} }

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('push store', () => {
  let fetchMock: ReturnType<typeof vi.fn>
  const calls = () => fetchMock.mock.calls.map(([url, init]) => [url, init?.method, init?.body ? JSON.parse(init.body as string) : null])

  beforeEach(() => {
    localStorage.clear()
    localStorage.setItem('kompas.token', JSON.stringify('1|abc'))
    localStorage.setItem('kompas.user', JSON.stringify(user))
    setActivePinia(createPinia())
    vi.clearAllMocks()
    fetchMock = vi.fn(async () => json({ push: { available: true, devices: 1 } }))
    vi.stubGlobal('fetch', fetchMock)
  })

  it('sends the phone\'s token on switching on and keeps it', async () => {
    const push = usePush()
    await push.enable()

    expect(calls()).toEqual([['/api/push/devices', 'POST', { token: 'fcm-token-1', platform: 'android' }]])
    expect(push.enabled).toBe(true)
    expect(JSON.parse(localStorage.getItem('kompas.pushToken')!)).toBe('fcm-token-1')
    expect(useAuth().user?.push).toEqual({ available: true, devices: 1 })
  })

  it('passes a rotated token on only while on', async () => {
    const push = usePush()
    push.onToken('fcm-token-2')
    expect(fetchMock).not.toHaveBeenCalled()

    await push.enable()
    push.onToken('fcm-token-2')
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
    expect(calls()[1]).toEqual(['/api/push/devices', 'POST', { token: 'fcm-token-2', platform: 'android' }])
  })

  it('refreshes the token each start, and switches off when the phone took the permission away', async () => {
    const push = usePush()
    await push.refresh()
    expect(registerPush).not.toHaveBeenCalled()

    await push.enable()
    await push.refresh()
    expect(calls().map(([url, method]) => `${method} ${url}`)).toEqual(['POST /api/push/devices', 'POST /api/push/devices'])

    vi.mocked(pushGranted).mockResolvedValueOnce(false)
    fetchMock.mockResolvedValueOnce(json({ push: { available: true, devices: 0 } }))
    await push.refresh()
    expect(calls()[2]).toEqual(['/api/push/devices', 'DELETE', { token: 'fcm-token-1' }])
    expect(push.enabled).toBe(false)
    expect(unregisterPush).toHaveBeenCalled()
    expect(useAuth().user?.push?.devices).toBe(0)
  })

  it('switches off on the server and in Firebase, and only locally on sign-out', async () => {
    const push = usePush()
    await push.enable()
    await push.disable()
    expect(calls()[1]).toEqual(['/api/push/devices', 'DELETE', { token: 'fcm-token-1' }])
    expect(localStorage.getItem('kompas.pushToken')).toBeNull()

    await push.enable()
    fetchMock.mockClear()
    push.reset()
    expect(push.enabled).toBe(false)
    expect(fetchMock).not.toHaveBeenCalled()
    expect(unregisterPush).toHaveBeenCalledTimes(2)
  })
})
