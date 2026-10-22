import { NativeBiometric } from '@capgo/capacitor-native-biometric'
import { isNative } from './native'
import { readJson, writeJson } from './storage'

// Secrets of this device: the session token and the PIN hash. In the Android app they are
// encrypted with a key kept in the Android keystore; in the browser they stay in localStorage
// like everything else. The phone's copy is read once before the app starts (loadSecrets),
// so reading stays synchronous and the stores do not change.

const cache = new Map<string, string>()

export async function loadSecrets(keys: readonly string[]): Promise<void> {
  if (!isNative) return
  for (const key of keys) {
    try {
      const { isSaved } = await NativeBiometric.isDataSaved({ key })
      if (isSaved) {
        cache.set(key, (await NativeBiometric.getData({ key })).value)
        continue
      }
    } catch {
      // The keystore refused (rare, e.g. restored from another phone): sign in again.
      continue
    }
    // Saved by a build that had no secure storage yet: move it over once.
    const legacy = readJson<unknown>(key)
    if (legacy !== null) {
      writeSecret(key, legacy)
      writeJson(key, null)
    }
  }
}

export function readSecret<T>(key: string): T | null {
  if (!isNative) return readJson<T>(key)
  const raw = cache.get(key)
  return raw === undefined ? null : (JSON.parse(raw) as T)
}

export function writeSecret(key: string, value: unknown): void {
  if (!isNative) {
    writeJson(key, value)
    return
  }
  if (value === null || value === undefined) {
    cache.delete(key)
    NativeBiometric.deleteData({ key }).catch(() => {})
  } else {
    const raw = JSON.stringify(value)
    cache.set(key, raw)
    // Not awaited: the copy in memory serves this run; losing the write only means signing in again.
    NativeBiometric.setData({ key, value: raw }).catch(() => {})
  }
}
