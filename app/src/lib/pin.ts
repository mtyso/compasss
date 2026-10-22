// The PIN is a lock screen on this device: it never leaves the device and is kept
// only as a PBKDF2 hash. Too many wrong tries sign the device out (stores/auth.ts).

export interface PinRecord {
  salt: string
  hash: string
  iterations: number
}

export const PIN_LENGTH = 4
const ITERATIONS = 150_000

function toBase64(bytes: Uint8Array): string {
  return btoa(String.fromCharCode(...bytes))
}

function fromBase64(value: string): Uint8Array<ArrayBuffer> {
  return Uint8Array.from(atob(value), (c) => c.charCodeAt(0))
}

async function derive(pin: string, salt: Uint8Array<ArrayBuffer>, iterations: number): Promise<string> {
  const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(pin), 'PBKDF2', false, ['deriveBits'])
  const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations }, key, 256)
  return toBase64(new Uint8Array(bits))
}

export async function createPinRecord(pin: string): Promise<PinRecord> {
  const salt = crypto.getRandomValues(new Uint8Array(16))
  return { salt: toBase64(salt), hash: await derive(pin, salt, ITERATIONS), iterations: ITERATIONS }
}

export async function verifyPin(pin: string, record: PinRecord): Promise<boolean> {
  return (await derive(pin, fromBase64(record.salt), record.iterations)) === record.hash
}
