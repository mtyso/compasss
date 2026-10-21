import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { bindBackButton, isNestedPath, launchedFromTelegram, setTelegramApp, syncColors, toHex, type TelegramWebApp } from './telegram'

function fakeApp(overrides: Partial<TelegramWebApp> = {}): TelegramWebApp {
  return {
    initData: 'user=%7B%7D&hash=x',
    initDataUnsafe: {},
    version: '9.0',
    platform: 'android',
    colorScheme: 'dark',
    isVersionAtLeast: () => true,
    ready: vi.fn(),
    expand: vi.fn(),
    close: vi.fn(),
    disableVerticalSwipes: vi.fn(),
    setHeaderColor: vi.fn(),
    setBackgroundColor: vi.fn(),
    setBottomBarColor: vi.fn(),
    onEvent: vi.fn(),
    offEvent: vi.fn(),
    BackButton: { isVisible: false, show: vi.fn(), hide: vi.fn(), onClick: vi.fn() },
    ...overrides,
  }
}

afterEach(() => setTelegramApp(null))

describe('launchedFromTelegram', () => {
  it('sees the launch data Telegram puts in the hash', () => {
    expect(launchedFromTelegram('#tgWebAppData=query_id%3D1&tgWebAppVersion=9.0', null)).toBe(true)
    expect(launchedFromTelegram('#foo=1&tgWebAppPlatform=ios', null)).toBe(true)
  })

  it('remembers it across a reload through the SDK session storage', () => {
    expect(launchedFromTelegram('', { getItem: (key) => (key === '__telegram__initParams' ? '{}' : null) })).toBe(true)
  })

  it('stays out of the way in a browser and in the Android app', () => {
    expect(launchedFromTelegram('', { getItem: () => null })).toBe(false)
    expect(launchedFromTelegram('#section', null)).toBe(false)
  })
})

describe('toHex', () => {
  it('turns CSS colours into the #RRGGBB Telegram accepts', () => {
    expect(toHex(' #F1F3F0 ')).toBe('#f1f3f0')
    expect(toHex('#fff')).toBe('#ffffff')
    expect(toHex('rgb(15, 20, 18)')).toBe('#0f1412')
    expect(toHex('rgba(23, 29, 26, 1)')).toBe('#171d1a')
    expect(toHex('')).toBeNull()
  })
})

describe('inside Telegram', () => {
  it('paints the header and background with the page colour and the bottom bar with the tab bar colour', () => {
    document.documentElement.style.setProperty('--c-bg', '#0f1412')
    document.documentElement.style.setProperty('--c-surface', '#171d1a')
    const app = fakeApp()
    setTelegramApp(app)

    syncColors()

    expect(app.setHeaderColor).toHaveBeenCalledWith('#0f1412')
    expect(app.setBackgroundColor).toHaveBeenCalledWith('#0f1412')
    expect(app.setBottomBarColor).toHaveBeenCalledWith('#171d1a')
  })

  it('shows Telegram’s Back button only below a tab and goes up when there is no history', async () => {
    const app = fakeApp()
    setTelegramApp(app)
    const component = { template: '<div />' }
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component },
        { path: '/more', component },
        { path: '/more/accounts', component },
      ],
    })
    bindBackButton(router)

    await router.push('/more/accounts')
    expect(app.BackButton.show).toHaveBeenCalledOnce()

    const onBack = vi.mocked(app.BackButton.onClick).mock.calls[0][0]
    onBack()
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/more'))
    expect(app.BackButton.hide).toHaveBeenCalled()
  })

  it('knows which screens are nested', () => {
    expect(isNestedPath('/')).toBe(false)
    expect(isNestedPath('/money')).toBe(false)
    expect(isNestedPath('/more/accounts/12')).toBe(true)
  })
})
