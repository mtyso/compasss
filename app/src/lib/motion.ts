// Motion helpers for script-driven animations. CSS transitions use the tokens in style.css;
// these produce the same springs for the Web Animations API and for values animated by hand.

export function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

export interface Spring {
  /** CSS easing: linear() samples of the spring. */
  easing: string
  /** How long the spring takes to settle, ms. */
  duration: number
  /** Position at time t (ms), 0 → 1 with overshoot. */
  at: (t: number) => number
}

/**
 * A damped spring described the way designers tune them: a perceptual duration and how much it
 * bounces (0 = none, 0.3 = lively, 0.5 = playful).
 */
export function spring(perceptualMs: number, bounce = 0.15, samples = 40): Spring {
  const zeta = 1 - Math.min(Math.max(bounce, 0), 0.95)
  const w0 = (2 * Math.PI) / (perceptualMs / 1000)
  const wd = w0 * Math.sqrt(Math.max(1 - zeta * zeta, 1e-6))
  const position = (seconds: number): number =>
    1 - Math.exp(-zeta * w0 * seconds) * (Math.cos(wd * seconds) + ((zeta * w0) / wd) * Math.sin(wd * seconds))

  let settle = 0
  for (let s = 0; s < 4; s += 0.002) {
    if (Math.abs(1 - position(s)) > 0.002) settle = s
  }
  const points: number[] = []
  for (let i = 0; i <= samples; i++) {
    points.push(i === samples ? 1 : Math.round(position((settle * i) / samples) * 1000) / 1000)
  }
  return {
    easing: `linear(${points.join(', ')})`,
    duration: Math.round(settle * 1000),
    at: (t) => (t >= settle * 1000 ? 1 : position(t / 1000)),
  }
}

export const springs = {
  /** Sheets, thumbs, anything that moves on screen. */
  ui: spring(420, 0.12),
  /** Small things that appear: a selected chip, a toast, a filled PIN dot. */
  pop: spring(400, 0.32),
  /** The compass needle settling on its bearing. */
  needle: spring(900, 0.42, 64),
}

const supportsLinearEasing = typeof CSS !== 'undefined' && CSS.supports?.('transition-timing-function', 'linear(0, 1)')

/** Easing for Element.animate(): the spring where linear() works, a close cubic curve where it doesn't. */
export function easingOf(s: Spring): string {
  return supportsLinearEasing ? s.easing : 'cubic-bezier(0.2, 0.9, 0.1, 1)'
}

/**
 * Animates a number from `from` to `to`, calling `onFrame` on each frame. Returns a function that
 * stops it. Ease-out so the last digits settle slowly enough to read.
 */
export function tweenNumber(from: number, to: number, ms: number, onFrame: (value: number) => void): () => void {
  if (from === to || ms <= 0 || prefersReducedMotion()) {
    onFrame(to)
    return () => {}
  }
  const start = performance.now()
  let frame = 0
  const step = (now: number): void => {
    const t = Math.min(1, (now - start) / ms)
    const eased = 1 - Math.pow(1 - t, 4)
    onFrame(t === 1 ? to : from + (to - from) * eased)
    if (t < 1) frame = requestAnimationFrame(step)
  }
  frame = requestAnimationFrame(step)
  return () => cancelAnimationFrame(frame)
}
