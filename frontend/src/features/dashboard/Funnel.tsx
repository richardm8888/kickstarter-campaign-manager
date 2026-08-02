import type { FunnelStep } from '@/lib/types'
import { number, percent } from '@/lib/format'

const STEP_COLORS = [
  'var(--viz-ordinal-1)',
  'var(--viz-ordinal-2)',
  'var(--viz-ordinal-3)',
  'var(--viz-ordinal-4)',
  'var(--viz-ordinal-5)',
  'var(--viz-ordinal-6)',
]

/**
 * Pre-launch funnel as ordered horizontal bars (ordinal single-hue ramp).
 * Width encodes volume relative to the widest step; the conversion from
 * the previous step is labelled between rows.
 */
export function Funnel({ steps }: { steps: FunnelStep[] }) {
  const max = Math.max(1, ...steps.map((s) => s.value))

  return (
    <ol className="flex flex-col">
      {steps.map((step, i) => (
        <li key={step.key}>
          {i > 0 && (
            <p className="py-1 pl-3 text-xs text-muted-foreground">
              {step.conversion !== null ? `↓ ${percent(step.conversion)}` : '↓ —'}
            </p>
          )}
          <div className="flex items-center gap-3">
            <span className="w-36 shrink-0 text-sm text-muted-foreground">{step.label}</span>
            <div className="relative h-7 flex-1 overflow-hidden rounded-md bg-muted/50">
              <div
                className="absolute inset-y-0 left-0 rounded-r-[4px]"
                style={{
                  width: `${Math.max(step.value > 0 ? 2 : 0, (step.value / max) * 100)}%`,
                  background: STEP_COLORS[i] ?? STEP_COLORS[STEP_COLORS.length - 1],
                }}
              />
              <span className="absolute inset-y-0 right-2 flex items-center text-xs font-medium tabular-nums">
                {number(step.value)}
              </span>
            </div>
          </div>
        </li>
      ))}
    </ol>
  )
}
