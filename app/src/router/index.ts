import { createRouter, createWebHistory } from 'vue-router'
import AppShell from '@/layouts/AppShell.vue'
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
    { path: '/pin', name: 'pin-setup', component: () => import('@/pages/PinSetupPage.vue'), meta: { gate: true } },
    { path: '/lock', name: 'lock', component: () => import('@/pages/LockPage.vue'), meta: { gate: true } },
    {
      path: '/',
      component: AppShell,
      children: [
        { path: '', name: 'today', component: () => import('@/pages/TodayPage.vue') },
        { path: 'money', name: 'money', component: () => import('@/pages/MoneyPage.vue') },
        { path: 'tasks', name: 'tasks', component: () => import('@/pages/TasksPage.vue') },
        { path: 'more', name: 'more', component: () => import('@/pages/MorePage.vue') },
        { path: 'more/accounts', name: 'accounts', component: () => import('@/pages/AccountsPage.vue') },
        { path: 'more/accounts/new', name: 'account-new', component: () => import('@/pages/AccountFormPage.vue') },
        { path: 'more/accounts/:id(\\d+)', name: 'account-edit', component: () => import('@/pages/AccountFormPage.vue') },
        { path: 'more/categories', name: 'categories', component: () => import('@/pages/CategoriesPage.vue') },
        { path: 'more/budget', name: 'budget', component: () => import('@/pages/BudgetPage.vue') },
        { path: 'more/income', name: 'income', component: () => import('@/pages/IncomePage.vue') },
        { path: 'more/payments', name: 'payments', component: () => import('@/pages/PaymentsPage.vue') },
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
    return to.meta.public ? true : { name: 'login' }
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
