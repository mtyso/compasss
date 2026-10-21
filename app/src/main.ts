import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { i18n } from './i18n'
import { router } from './router'
import { useSettings } from './stores/settings'
import './style.css'

const app = createApp(App)
app.use(createPinia())
app.use(i18n)
app.use(router)

// Applies the saved language and theme before the first screen renders.
useSettings()

app.mount('#app')
