// Small wrapper over localStorage: it can be missing or throw (private mode, blocked
// site data), and then the app keeps working for the current session only.
// Secrets (the session token, the PIN hash) go through lib/secure.ts instead.

export function readJson<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(key)
    return raw ? (JSON.parse(raw) as T) : null
  } catch {
    return null
  }
}

export function writeJson(key: string, value: unknown): void {
  try {
    if (value === null || value === undefined) {
      localStorage.removeItem(key)
    } else {
      localStorage.setItem(key, JSON.stringify(value))
    }
  } catch {
    // See the note at the top.
  }
}
