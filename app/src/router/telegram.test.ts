import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { setTelegramApp, type TelegramWebApp } from '@/lib/telegram'
import { router } from './index'

describe('router inside the Telegram Mini App', () => {
  beforeEach(() => {
    localStorage.clear()
    setTelegramApp({ initData: 'auth_date=1&hash=abc', platform: 'android' } as TelegramWebApp)
    setActivePinia(createPinia())
  })

  afterEach(() => setTelegramApp(null))

  it('signs in first and comes back to the screen a bot button opened, without the launch hash', async () => {
    await router.push('/money#tgWebAppData=query_id%3DAAE%26hash%3Dabc&tgWebAppVersion=8.0')

    expect(router.currentRoute.value.name).toBe('telegram')
    expect(router.currentRoute.value.query.next).toBe('/money')
  })
})
