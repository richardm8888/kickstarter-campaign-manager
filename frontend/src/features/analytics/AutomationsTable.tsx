import { useState } from 'react'
import { ChevronRight } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { cn } from '@/lib/utils'
import { number, percent } from '@/lib/format'
import type { EmailAutomation, EmailPerformance } from '@/lib/types'

/**
 * The automated sequences, which send more than the broadcasts do.
 *
 * Presented apart from the charts above rather than folded into them.
 * A broadcast is one email on one day; a sequence drips for weeks and
 * reports a running total with no send date attached. Adding them
 * together would produce a number that means nothing, and putting the
 * automation's total on a daily chart would put months of sending on
 * whichever day the sync happened to run.
 */
function Rate({ value }: { value: number | null }) {
  if (value === null) return <span className="text-muted-foreground">—</span>

  return <span className="tabular-nums">{percent(value, 1)}</span>
}

function Automation({ automation }: { automation: EmailAutomation }) {
  const [open, setOpen] = useState(false)
  const hasSteps = automation.steps.length > 0

  return (
    <div className="border-b border-border/50 last:border-0">
      <div className="flex items-center gap-3 py-3">
        <button
          type="button"
          onClick={() => setOpen((was) => !was)}
          aria-expanded={open}
          disabled={!hasSteps}
          className="flex min-w-0 flex-1 items-center gap-2 text-left disabled:cursor-default"
        >
          {hasSteps ? (
            <ChevronRight
              aria-hidden
              className={cn('h-4 w-4 shrink-0 text-muted-foreground transition-transform', open && 'rotate-90')}
            />
          ) : (
            <span className="w-4 shrink-0" aria-hidden />
          )}
          <span className="min-w-0">
            <span className="block truncate font-medium">{automation.name}</span>
            <span className="block text-xs text-muted-foreground">
              {automation.enabled ? 'Running' : 'Paused'}
              {hasSteps && ` · ${automation.steps.length} email${automation.steps.length === 1 ? '' : 's'}`}
            </span>
          </span>
        </button>

        <dl className="flex shrink-0 gap-5 text-right text-sm">
          <div>
            <dt className="text-xs text-muted-foreground">Sent</dt>
            <dd className="tabular-nums">{number(automation.sent)}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Opened</dt>
            <dd><Rate value={automation.open_rate} /></dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Clicked</dt>
            <dd><Rate value={automation.click_rate} /></dd>
          </div>
        </dl>
      </div>

      {open && hasSteps && (
        <ul className="mb-3 ml-6 flex flex-col gap-2 border-l border-border pl-4">
          {automation.steps.map((step) => (
            <li key={`${step.position}-${step.name}`} className="flex items-baseline justify-between gap-4">
              <div className="min-w-0">
                <p className="truncate text-sm">
                  <span className="text-muted-foreground">{step.position}.</span> {step.name}
                </p>
                {step.subject && (
                  <p className="truncate text-xs text-muted-foreground">{step.subject}</p>
                )}
              </div>
              <p className="shrink-0 text-xs tabular-nums text-muted-foreground">
                {number(step.sent)} sent · <Rate value={step.open_rate} /> opened ·{' '}
                <Rate value={step.click_rate} /> clicked
              </p>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

export function AutomationsTable({ performance }: { performance: EmailPerformance }) {
  const { automations, totals } = performance

  return (
    <Card>
      <CardHeader>
        <CardTitle>Automated sequences</CardTitle>
        <CardDescription>
          Totals since each sequence was switched on, not the last 30 days — a drip has no send
          date, so these cannot sit on the charts above with the broadcasts.
        </CardDescription>
      </CardHeader>

      <CardContent>
        {automations.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            No automations found on your MailerLite account.
          </p>
        ) : (
          <>
            <div className="flex flex-col">
              {automations.map((automation) => (
                <Automation key={automation.id} automation={automation} />
              ))}
            </div>

            {totals !== null && totals.automated_share !== null && (
              <p className="mt-4 text-sm">
                <span className="font-medium">
                  {percent(totals.automated_share, 0)} of your email
                </span>{' '}
                goes out automatically — {number(totals.automated_sent)} from sequences against{' '}
                {number(totals.broadcast_sent)} from campaigns you sent by hand.
              </p>
            )}
          </>
        )}
      </CardContent>
    </Card>
  )
}
