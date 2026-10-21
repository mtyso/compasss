// Open sheets and dialogs, newest last. The Android back button and Telegram's back button close the
// top one before they navigate: call closeTopOverlay() first and navigate only when it returns false.

const stack: (() => void)[] = []

export function pushOverlay(close: () => void): () => void {
  stack.push(close)
  return () => {
    const index = stack.lastIndexOf(close)
    if (index !== -1) stack.splice(index, 1)
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
