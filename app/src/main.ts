import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { i18n } from './i18n'
import { closeTopOverlay, overlayDepth } from './components/ui/overlays'
import { setupNative } from './lib/native'
import { loadSecrets } from './lib/secure'
import { bindBackButton, initTelegram } from './lib/telegram'
import { router } from './router'
import { SECRET_KEYS } from './stores/auth'
import { useSettings } from './stores/settings'
import './style.css'

async function start(): Promise<void> {
  // Inside Telegram the SDK has to read its launch data from the URL before the router redirects.
  await initTelegram()
  // On the phone the session lives in the Android keystore: read it before the router asks who is signed in.
  await loadSecrets(SECRET_KEYS)

  const app = createApp(App)
  app.use(createPinia())
  app.use(i18n)
  app.use(router)

  // Applies the saved language and theme before the first screen renders.
  useSettings()
  bindBackButton(router, { depth: overlayDepth, closeTop: closeTopOverlay })
  setupNative()

  app.mount('#app')
}

start()
