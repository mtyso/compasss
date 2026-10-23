import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({
  native: true,
  permission: 'prompt' as string,
  answer: 'granted' as string,
  listeners: {} as Record<string, (arg: unknown) => void>,
}))

vi.mock('./native', () => ({
  get isNative() {
    return state.native
  },
}))

const plugin = vi.hoisted(() => ({
  createChannel: vi.fn(async () => {}),
  addListener: vi.fn(async (event: string, fn: (arg: unknown) => void) => {
    state.listeners[event] = fn
    return { remove: async () => {} }
  }),
  checkPermissions: vi.fn(async () => ({ receive: state.permission })),
  requestPermissions: vi.fn(async () => ({ receive: state.answer })),
  // Firebase answers a moment later, as on the phone.
  register: vi.fn(async () => void setTimeout(() => state.listeners.registration?.({ value: 'fcm-token-1' }), 0)),
  unregister: vi.fn(async () => {}),
}))

vi.mock('@capacitor/push-notifications', () => ({ PushNotifications: plugin }))

async function freshModule(enabled = 'true') {
  vi.resetModules()
  vi.stubEnv('VITE_PUSH_ENABLED', enabled)
  return import('./push')
}

describe('push notifications on the phone', () => {
  beforeEach(() => {
    state.native = true
    state.permission = 'prompt'
    state.answer = 'granted'
    state.listeners = {}
    vi.clearAllMocks()
  })
  afterEach(() => vi.unstubAllEnvs())

  it('stays away from the plugin when the APK has no Firebase config, or in the browser', async () => {
    const off = await freshModule('')
    expect(off.pushSupported).toBe(false)
    await off.setupPush({ token: vi.fn(), open: vi.fn() })
    await expect(off.registerPush()).rejects.toThrow()
    expect(await off.pushGranted()).toBe(false)

    state.native = false
    expect((await freshModule()).pushSupported).toBe(false)
    expect(plugin.createChannel).not.toHaveBeenCalled()
    expect(plugin.register).not.toHaveBeenCalled()
  })

  it('asks once, registers and gives the token to the caller, later rotations to the listener', async () => {
    const push = await freshModule()
    const token = vi.fn()
    await push.setupPush({ token, open: vi.fn() })
    expect(plugin.createChannel).toHaveBeenCalledWith(expect.objectContaining({ id: 'kompas', importance: 4 }))

    await expect(push.registerPush()).resolves.toBe('fcm-token-1')
    expect(plugin.requestPermissions).toHaveBeenCalledOnce()
    expect(token).not.toHaveBeenCalled()

    state.listeners.registration?.({ value: 'fcm-token-2' })
    expect(token).toHaveBeenCalledWith('fcm-token-2')
  })

  it('says no when notifications are not allowed', async () => {
    const push = await freshModule()
    await push.setupPush({ token: vi.fn(), open: vi.fn() })
    state.answer = 'denied'
    await expect(push.registerPush()).rejects.toBeInstanceOf(push.PushDenied)
    expect(plugin.register).not.toHaveBeenCalled()

    state.permission = 'denied'
    expect(await push.pushGranted()).toBe(false)
    state.permission = 'granted'
    expect(await push.pushGranted()).toBe(true)
  })

  it('opens the screen a tap names, only the app\'s own', async () => {
    const push = await freshModule()
    const open = vi.fn()
    await push.setupPush({ token: vi.fn(), open })
    const tap = (url: unknown) => state.listeners.pushNotificationActionPerformed?.({ actionId: 'tap', notification: { id: '1', data: { url } } })

    tap('/money/report/2026-10')
    tap('https://example.com')
    tap('//example.com/x')
    tap(undefined)
    expect(open.mock.calls).toEqual([['/money/report/2026-10']])
    expect(push.safePath('/tasks/calendar?view=day&date=2026-10-23')).toBe('/tasks/calendar?view=day&date=2026-10-23')
    expect(push.safePath('/\\evil')).toBeNull()
  })
})
