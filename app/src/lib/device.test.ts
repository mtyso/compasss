import { describe, expect, it } from 'vitest'
import { deviceName } from './device'

describe('deviceName', () => {
  it.each([
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36', 'Chrome, Windows'],
    ['Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Mobile Safari/537.36', 'Chrome, Android'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari, iPhone'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 YaBrowser/25.8 Safari/537.36', 'Яндекс Браузер, Windows'],
    ['curl/8.5', 'Web'],
  ])('%s', (ua, expected) => {
    expect(deviceName(ua)).toBe(expected)
  })
})
