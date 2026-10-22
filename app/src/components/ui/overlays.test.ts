import { describe, expect, it, vi } from 'vitest'
import { closeTopOverlay, hasOverlay, overlayDepth, pushOverlay } from './overlays'

describe('overlay stack', () => {
  it('closes the newest overlay first and tracks the depth', () => {
    const first = vi.fn()
    const second = vi.fn()
    const removeFirst = pushOverlay(first)
    const removeSecond = pushOverlay(second)
    expect(overlayDepth.value).toBe(2)

    expect(closeTopOverlay()).toBe(true)
    expect(second).toHaveBeenCalledOnce()
    expect(first).not.toHaveBeenCalled()

    // The sheet removes itself once it actually closes.
    removeSecond()
    removeFirst()
    expect(overlayDepth.value).toBe(0)
    expect(hasOverlay()).toBe(false)
    expect(closeTopOverlay()).toBe(false)
  })
})
