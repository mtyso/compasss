// Open sheets and dialogs, newest last. The Android back button and Telegram's back button close the
// top one before they navigate: call closeTopOverlay() first and navigate only when it returns false.
// overlayDepth is reactive, so a back button can show itself while a sheet is open on a tab screen.

import { readonly, ref } from 'vue'

const stack: (() => void)[] = []
const depth = ref(0)

export const overlayDepth = readonly(depth)

export function pushOverlay(close: () => void): () => void {
  stack.push(close)
  depth.value = stack.length
  return () => {
    const index = stack.lastIndexOf(close)
    if (index !== -1) stack.splice(index, 1)
    depth.value = stack.length
  }
}

export function closeTopOverlay(): boolean {
  const close = stack.at(-1)
  if (!close) return false
  close()
  return true
}

export function hasOverlay(): boolean {
  return stack.length > 0
}
