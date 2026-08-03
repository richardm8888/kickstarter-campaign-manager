import type { Funnel as FunnelData, FunnelStep } from '@/lib/types'
import { money, number, percent } from '@/lib/format'

const STEP_COLORS = [
  'var(--viz-ordinal-1)',
  'var(--viz-ordinal-2)',
  'var(--viz-ordinal-3)',
  'var(--viz-ordinal-4)',
  'var(--viz-ordinal-5)',
  'var(--viz-ordinal-6)',
] as const

/** The ramp darkens down the funnel, holding at its last step. */
function stepColor(index: number): string {
  return STEP_COLORS[Math.min(index, STEP_COLORS.length - 1)]!
}

function Bar({
  value,
  max,
  color,
  height = 'h-7',
}: {
  value: number
  max: number
  color: string
  height?: string
}) {
  return (
    <div className={`relative ${height} flex-1 overflow-hidden rounded-md bg-muted/50`}>
      <div
        className="absolute inset-y-0 left-0 rounded-r-[4px]"
        style={{ width: `${Math.max(value > 0 ? 2 : 0, (value / max) * 100)}%`, background: color }}
      />
      <span className="absolute inset-y-0 right-2 flex items-center text-xs font-medium tabular-nums">
        {number(value)}
      </span>
    </div>
  )
}

function Arrow({ conversion }: { conversion: number | null }) {
  return (
    <p className="py-1 pl-3 text-xs text-muted-foreground">
      {conversion !== null ? `↓ ${percent(conversion)}` : '↓ —'}
    </p>
  )
}

/**
 * Pre-launch funnel as ordered horizontal bars (ordinal single-hue ramp).
 *
 * Impressions lead, but as a headline rather than a bar: they run one to two
 * orders of magnitude above everything below them, and drawn to the same
 * scale they would flatten every later step into an identical sliver. The
 * bars are scaled from ad clicks down, where the comparisons are the ones a
 * creator can act on.
 *
 * Clicks then split into landing page views and form views, because the
 * recommended ads send people to different places — a page in a browser or a
 * form inside Meta — and merging them would hide which route is working.
 */
export function Funnel({ funnel, currency = 'GBP' }: { funnel: FunnelData; currency?: string }) {
  const [impressions, ...steps] = funnel.steps

  if (!impressions) {
    return null
  }

  const max = Math.max(1, ...steps.map((s) => s.value))

  return (
    <div>
      <Headline step={impressions} currency={currency} />

      <ol className="flex flex-col">
        {steps.map((step, i) => (
          <li key={step.key}>
            <Arrow conversion={step.conversion} />

            <div className="flex items-center gap-3">
              <span className="w-36 shrink-0 text-sm text-muted-foreground">{step.label}</span>
              <Bar value={step.value} max={max} color={stepColor(i + 1)} />
            </div>

            {step.branches && (
              <ul className="mt-1.5 flex flex-col gap-1.5 border-l border-border pl-3">
                {step.branches.map((branch) => (
                  <li key={branch.key} className="flex items-center gap-3">
                    <span className="w-[7.5rem] shrink-0 text-xs text-muted-foreground">
                      {branch.label}
                      {branch.conversion !== null && (
                        <span className="ml-1 tabular-nums">{percent(branch.conversion)}</span>
                      )}
                    </span>
                    <Bar value={branch.value} max={max} color={stepColor(i + 1)} height="h-5" />
                  </li>
                ))}
              </ul>
            )}

            {step.note && <p className="pl-3 pt-1 text-xs text-muted-foreground">{step.note}</p>}
          </li>
        ))}
      </ol>
    </div>
  )
}

function Headline({ step, currency }: { step: FunnelStep; currency: string }) {
  return (
    <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
      <span className="text-2xl font-semibold tabular-nums">{number(step.value)}</span>
      <span className="text-sm text-muted-foreground">
        {step.label.toLowerCase()}
        {step.spend !== undefined && step.spend > 0 && (
          <span className="ml-1">({money(step.spend, currency)} spent)</span>
        )}
      </span>
    </div>
  )
}
