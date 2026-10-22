import { NativeBiometric } from '@capgo/capacitor-native-biometric'
import { isNative } from './native'

// Fingerprint (or face) unlock in the Android app, on top of the PIN (D7). The PIN stays the
// fallback: the system prompt only says yes or no, and nothing is stored behind it.

export interface BiometricPrompt {
  title: string
  subtitle?: string
  cancel: string
}

/** True on a phone with a fingerprint or face enrolled; always false in the browser. */
export async function biometricAvailable(): Promise<boolean> {
  if (!isNative) return false
  try {
    return (await NativeBiometric.isAvailable()).isAvailable
  } catch {
    return false
  }
}

/** Shows the system prompt; false when the owner cancels or the finger is not recognised. */
export async function confirmBiometric(prompt: BiometricPrompt): Promise<boolean> {
  try {
    await NativeBiometric.verifyIdentity({ title: prompt.title, subtitle: prompt.subtitle, negativeButtonText: prompt.cancel, maxAttempts: 5 })
    return true
  } catch {
    return false
  }
}
