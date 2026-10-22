// Digit grouping while typing: «85000» shows as «85 000» as you type it. Only plain whole numbers
// (with an optional minus) are grouped; «32к», «12.5» and anything else stay exactly as typed.
// parseAmount() ignores the spaces, so the grouped text parses the same.

const SPACES = /[\s  ]/g

export function groupDigits(raw: string): string {
  const match = /^([-−]?)([\d\s  ]+)$/.exec(raw)
  if (!match) return raw
  return match[1] + match[2]!.replace(SPACES, '').replace(/\B(?=(\d{3})+(?!\d))/g, ' ')
}

/** @input handler for an amount field: groups the digits and keeps the caret after the same digit. */
export function groupInput(event: Event, set: (text: string) => void): void {
  const input = event.target as HTMLInputElement
  const raw = input.value
  const grouped = groupDigits(raw)
  set(grouped)
  if (grouped === raw) return
  const caret = input.selectionStart ?? raw.length
  const digitsBefore = raw.slice(0, caret).replace(/\D/g, '').length
  let position = 0
  for (let seen = 0; position < grouped.length && seen < digitsBefore; position++) {
    if (/\d/.test(grouped[position]!)) seen++
  }
  input.value = grouped
  input.setSelectionRange(position, position)
}
