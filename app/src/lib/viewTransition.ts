import { nextTick } from 'vue'
import type { Router } from 'vue-router'
import { prefersReducedMotion } from './motion'

/**
 * Screen changes through the View Transitions API: the old and the new screen move at the same
 * time instead of one after the other, and elements that exist on both (a goal's icon in the list
 * and on its own screen) fly from one place to the other. style.css has the animations; the kind of
 * change goes on <html data-vt="…">, and names are only given while it is set, so the theme switch
 * (which also uses a view transition) is not affected.
 *
 * Where the API is missing (old WebViews) or motion is reduced, nothing is installed and the
 * screens fall back to the Vue <Transition> in AppShell.
 */
export type ScreenChange = 'push' | 'pop' | 'through'

type StartViewTransition = (update: () => Promise<void>) => { finished: Promise<void> }

function starter(): StartViewTransition | null {
  if (typeof document === 'undefined') return null
  const start = (document as Document & { startViewTransition?: StartViewTransition }).startViewTransition
  return typeof start === 'function' && !prefersReducedMotion() ? start.bind(document) : null
}

export const screenTransitions = starter() !== null

/**
 * Wraps each navigation in a view transition. `kind` says how the screens move; `beforeReveal` runs
 * once the new screen is in the DOM, before it is captured (restoring its scroll position, say).
 * Returns a function that removes the guards.
 */
export function installScreenTransitions(router: Router, kind: () => ScreenChange, beforeReveal: () => void): () => void {
  const start = starter()
  if (!start) return () => {}
  const root = document.documentElement
  let reveal: (() => void) | null = null
  let count = 0

  const removeResolve = router.beforeResolve((to, from) => {
    if (to.path === from.path || reveal) return
    const token = ++count
    root.dataset.vt = kind()
    return new Promise<void>((proceed) => {
      const transition = start(
        () =>
          new Promise<void>((done) => {
            reveal = done
            proceed()
          }),
      )
      transition.finished.finally(() => {
        if (count === token) delete root.dataset.vt
      })
    })
  })

  const removeAfter = router.afterEach(async () => {
    const done = reveal
    if (!done) return
    reveal = null
    await nextTick()
    beforeReveal()
    done()
  })

  return () => {
    removeResolve()
    removeAfter()
    reveal?.()
    reveal = null
  }
}
