// A readable name for the sign-in confirmation in the bot, e.g. «Chrome, Windows».
export function deviceName(userAgent: string = navigator.userAgent): string {
  const os =
    [
      [/Android/, 'Android'],
      [/iPhone|iPad/, 'iPhone'],
      [/Windows/, 'Windows'],
      [/Mac OS X/, 'Mac'],
      [/Linux/, 'Linux'],
    ] as const
  const browsers = [
    [/YaBrowser/, 'Яндекс Браузер'],
    [/Edg\//, 'Edge'],
    [/OPR\//, 'Opera'],
    [/Firefox\//, 'Firefox'],
    [/Chrome\//, 'Chrome'],
    [/Safari\//, 'Safari'],
  ] as const

  const system = os.find(([pattern]) => pattern.test(userAgent))?.[1]
  const browser = browsers.find(([pattern]) => pattern.test(userAgent))?.[1]

  return [browser, system].filter(Boolean).join(', ') || 'Web'
}
