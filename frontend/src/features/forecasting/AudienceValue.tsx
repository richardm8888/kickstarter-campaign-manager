import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { money, number, percent } from '@/lib/format'
import type { AudienceValue as Segment } from '@/lib/types'

/**
 * What each audience is worth, side by side.
 *
 * The three groups back at rates an order of magnitude apart, so the useful
 * comparison is not how many people a creator has but what those people are
 * worth. Showing the value of one more of each turns the abstract rate into
 * a number that can be compared directly against a cost per signup.
 */
export function AudienceValuePanel({
  segments,
  currency = 'GBP',
}: {
  segments: Segment[]
  currency?: string
}) {
  const total = segments.reduce((sum, s) => sum + s.backers, 0)
  const best = segments.reduce(
    (top, s) => (s.value_each > top.value_each ? s : top),
    segments[0]!,
  )

  return (
    <Card>
      <CardHeader>
        <CardTitle>What your audience is worth</CardTitle>
        <CardDescription>
          {number(total)} backers today. Not everyone counts the same.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <dl className="flex flex-col gap-3">
          {segments.map((segment) => (
            <div key={segment.segment} className="flex items-baseline justify-between gap-3">
              <div>
                <dt className="text-sm">{segment.label}</dt>
                <dd className="text-xs text-muted-foreground">
                  {number(segment.count)} × {percent(segment.rate * 100, 0)} back (
                  {percent(segment.rate_low * 100, 0)}–{percent(segment.rate_high * 100, 0)})
                </dd>
              </div>
              <div className="text-right">
                <p className="text-sm font-semibold tabular-nums">
                  {number(segment.backers)} backers
                </p>
                <p className="text-xs text-muted-foreground tabular-nums">
                  {money(segment.value_each, currency)} each
                </p>
              </div>
            </div>
          ))}
        </dl>

        <p className="border-t border-border pt-3 text-xs text-muted-foreground">
          {best.label} are your best audience at {money(best.value_each, currency)} a head — spend up
          to that to win one and the campaign still pays for itself.
        </p>
      </CardContent>
    </Card>
  )
}
