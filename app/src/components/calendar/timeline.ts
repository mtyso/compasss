// The day and week timelines scroll with the page (the `main` scroller of AppShell), under the
// sticky head of the calendar and the view's own sticky part (the all-day area, the day headers).

/** The hour lines behind a timeline: one gradient repeated every hour, not 24 elements. */
export function hourLines(hourHeight: number): Record<string, string> {
  return {
    backgroundImage: 'linear-gradient(to bottom, var(--c-line) 1px, transparent 1px)',
    backgroundSize: `100% ${hourHeight}px`,
  }
}

/**
 * Scrolls the page so that `offset` px into `timeline` sits just under the sticky parts above it.
 * `sticky` is the view's sticky part; it is stuck at its CSS `top`, under the calendar's head.
 */
export function scrollTimeline(timeline: HTMLElement | null, sticky: HTMLElement | null, offset: number): void {
  const scroller = timeline?.closest<HTMLElement>('[data-scroller]')
  if (!timeline || !scroller) return
  const stuckBottom = sticky ? (parseFloat(getComputedStyle(sticky).top) || 0) + sticky.offsetHeight : 0
  const paddingTop = parseFloat(getComputedStyle(scroller).paddingTop) || 0
  const timelineTop = timeline.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop
  scroller.scrollTop = Math.max(0, timelineTop + offset - paddingTop - stuckBottom - 8)
}

/** Whether the page was scrolled already (AppShell put back where it was when coming back to the screen). */
export function scrolledAlready(element: HTMLElement | null): boolean {
  return (element?.closest<HTMLElement>('[data-scroller]')?.scrollTop ?? 0) > 0
}
