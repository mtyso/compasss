import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { i18n } from './i18n'
import { setupNative } from './lib/native'
import { loadSecrets } from './lib/secure'
import { router } from './router'
import { SECRET_KEYS } from './stores/auth'
import { useSettings } from './stores/settings'
import './style.css'

async function start(): Promise<void> {
  // On the phone the session lives in the Android keystore: read it before the router asks who is signed in.
  await loadSecrets(SECRET_KEYS)

  const app = createApp(App)
  app.use(createPinia())
  app.use(i18n)
  app.use(router)

  // Applies the saved language and theme before the first screen renders.
  useSettings()
  setupNative()

  app.mount('#app')
}

start()
