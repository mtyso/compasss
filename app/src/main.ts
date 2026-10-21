import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { i18n } from './i18n'
import { bindBackButton, initTelegram } from './lib/telegram'
import { router } from './router'
import { useSettings } from './stores/settings'
import './style.css'

async function start(): Promise<void> {
  // Inside Telegram the SDK has to read its launch data from the URL before the router redirects.
  await initTelegram()

  const app = createApp(App)
  app.use(createPinia())
  app.use(i18n)
  app.use(router)

  // Applies the saved language and theme before the first screen renders.
  useSettings()
  bindBackButton(router)

  app.mount('#app')
}

start()
