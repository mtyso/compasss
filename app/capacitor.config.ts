import type { CapacitorConfig } from '@capacitor/cli'

// The Android app wraps the same SPA as the web (D3). It talks to the production API through
// VITE_API_URL, baked in at build time; links to other hosts (t.me) open outside the app.
const config: CapacitorConfig = {
  appId: 'uz.kompas.app',
  appName: 'Компас',
  webDir: 'dist',
  plugins: {
    // A notification that comes while the app is open still shows, as it would on the lock screen.
    PushNotifications: { presentationOptions: ['alert', 'sound'] },
  },
}

export default config
