// In the browser the SPA is served by Laravel, so the API is on the same origin.
// Inside the Android app (Capacitor) there is no server origin, so VITE_API_URL points at the production host.
const BASE_URL = (import.meta.env.VITE_API_URL ?? '').replace(/\/$/, '')

export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

let token: string | null = null

export function setApiToken(value: string | null): void {
  token = value
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  const response = await fetch(`${BASE_URL}/api${path}`, { ...init, headers })
  const isJson = response.headers.get('Content-Type')?.includes('application/json')
  const body = isJson ? await response.json() : null

  if (!response.ok) {
    throw new ApiError(response.status, body?.message ?? response.statusText, body?.errors ?? {})
  }
  return body as T
}
