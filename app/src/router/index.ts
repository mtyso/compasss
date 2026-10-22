import { createRouter, createWebHistory } from 'vue-router'
import AppShell from '@/layouts/AppShell.vue'
import { inTelegram } from '@/lib/telegram'
import { useAuth } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    /** Reachable without signing in. */
    public?: boolean
    /** Part of signing in or unlocking: reachable while the app is locked. */
    gate?: boolean
  }
}

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { public: true } },
    { path: '/telegram', name: 'telegram', component: () => import('@/pages/TelegramEntryPage.vue'), meta: { public: true } },
    { path: '/pin', name: 'pin-setup', component: () => import('@/pages/PinSetupPage.vue'), meta: { gate: true } },
    { path: '/lock', name: 'lock', component: () => import('@/pages/LockPage.vue'), meta: { gate: true } },
    {
      path: '/',
      component: AppShell,
      children: [
        { path: '', name: 'today', component: () => import('@/pages/TodayPage.vue') },
        { path: 'money', name: 'money', component: () => import('@/pages/MoneyPage.vue') },
        { path: 'money/report/:month(\\d{4}-\\d{2})?', name: 'report', component: () => import('@/pages/ReportPage.vue') },
        { path: 'tasks', name: 'tasks', component: () => import('@/pages/TasksPage.vue') },
        { path: 'goals', name: 'goals', component: () => import('@/pages/GoalsPage.vue') },
        { path: 'goals/:id(\\d+)', name: 'goal', component: () => import('@/pages/GoalPage.vue') },
        { path: 'more', name: 'more', component: () => import('@/pages/MorePage.vue') },
        { path: 'more/accounts', name: 'accounts', component: () => import('@/pages/AccountsPage.vue') },
        { path: 'more/accounts/new', name: 'account-new', component: () => import('@/pages/AccountFormPage.vue') },
        { path: 'more/accounts/:id(\\d+)', name: 'account-edit', component: () => import('@/pages/AccountFormPage.vue') },
        { path: 'more/categories', name: 'categories', component: () => import('@/pages/CategoriesPage.vue') },
        { path: 'more/budget', name: 'budget', component: () => import('@/pages/BudgetPage.vue') },
        { path: 'more/income', name: 'income', component: () => import('@/pages/IncomePage.vue') },
        { path: 'more/payments', name: 'payments', component: () => import('@/pages/PaymentsPage.vue') },
        { path: 'more/debts', name: 'debts', component: () => import('@/pages/DebtsPage.vue') },
        { path: 'advice', name: 'advice', component: () => import('@/pages/AdvicePage.vue') },
        { path: 'can-afford', name: 'can-afford', component: () => import('@/pages/CanAffordPage.vue') },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach((to) => {
  const auth = useAuth()

  if (!auth.signedIn) {
    if (inTelegram()) {
      // The Mini App signs in by itself, then comes back to the screen a bot button opened.
      // Without the hash: it holds Telegram's launch data (already read by the SDK), not an anchor.
      const next = to.fullPath.replace(/#.*$/, '')
      return to.name === 'telegram' ? true : { name: 'telegram', query: to.meta.public ? {} : { next } }
    }
    return to.meta.public && to.name !== 'telegram' ? true : { name: 'login' }
  }
  if (inTelegram()) {
    return to.meta.public || to.meta.gate ? { name: 'today' } : true
  }
  if (!auth.pin) {
    // Signed in on this device for the first time: choose a PIN before anything else.
    return to.name === 'pin-setup' ? true : { name: 'pin-setup' }
  }
  if (!auth.unlocked) {
    return to.name === 'lock' ? true : { name: 'lock', query: to.fullPath !== '/' ? { next: to.fullPath } : {} }
  }
  if (to.meta.public || to.name === 'lock') {
    return { name: 'today' }
  }
  return true
})
