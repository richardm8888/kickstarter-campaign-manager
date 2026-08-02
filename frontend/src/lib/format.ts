/** Format minor units (pence/cents) as currency, e.g. 450000 → £4,500. */
export function money(minorUnits: number, currency = 'GBP'): string {
  return new Intl.NumberFormat('en-GB', {
    style: 'currency',
    currency,
    maximumFractionDigits: minorUnits % 100 === 0 ? 0 : 2,
  }).format(minorUnits / 100)
}

export function number(value: number): string {
  return new Intl.NumberFormat('en-GB').format(value)
}

export function percent(value: number, digits = 1): string {
  return `${value.toFixed(digits)}%`
}

export function shortDate(iso: string): string {
  return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
}
