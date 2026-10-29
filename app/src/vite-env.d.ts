/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_URL?: string
  /** 'true' when the APK carries the Firebase config (CI, docs/DEPLOY.md): push notifications work. */
  readonly VITE_PUSH_ENABLED?: string
}
