import { useEffect, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Check, X } from 'lucide-react'
import { getForecast, saveAdSpend } from './api'
import { PageHeader } from '@/components/layout/ProjectLayout'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { money, number, percent } from '@/lib/format'

import type { FunnelRating } from '@/lib/types'

const RATING_COLOR: Record<FunnelRating['rating'], string> = {
  good: 'var(--status-good)',
  warning: 'var(--status-warning)',
  danger: 'var(--status-critical)',
}

/** A measured figure, coloured by whether it is healthy. */
function Rated({ label, value, rating, estimated }: {
  label: string
  value: string
  rating: FunnelRating
  estimated: boolean
}) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className="text-muted-foreground">
        {label}
        <span className="block text-xs">{rating.note}</span>
      </dt>
      <dd className="shrink-0 tabular-nums font-medium" style={{ color: RATING_COLOR[rating.rating] }}>
        {value}
        {estimated && <span className="ml-1.5 text-xs font-normal text-muted-foreground">(typical)</span>}
      </dd>
    </div>
  )
}

const LIKELIHOOD_STYLE: Record<string, string> = {
  'already there': 'success',
  likely: 'success',
  plausible: 'default',
  'a stretch': 'warning',
  unrealistic: 'destructive',
}

/** A number that must be hit, with how realistic that is. */
function Requirement({ label, value, detail, likelihood }: {
  label: string
  value: string
  detail: string
  likelihood: string
}) {
  return (
    <div className="flex flex-wrap items-baseline justify-between gap-2 border-t border-border pt-3">
      <div>
        <p className="text-sm font-medium">{label}</p>
        <p className="text-xs text-muted-foreground">{detail}</p>
      </div>
      <div className="flex items-center gap-2">
        <span className="text-lg font-semibold tabular-nums">{value}</span>
        <Badge
          variant={
            (LIKELIHOOD_STYLE[likelihood] ?? 'default') as
              'success' | 'default' | 'warning' | 'destructive'
          }
        >
          {likelihood}
        </Badge>
      </div>
    </div>
  )
}

export function ForecastPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const [spend, setSpend] = useState<string | null>(null)
  const [debounced, setDebounced] = useState<number | null>(null)

  const { data, isPending } = useQuery(getForecast(projectId, debounced))

  // Seed the field from the saved budget, once.
  useEffect(() => {
    if (data && spend === null) {
      setSpend(String(data.measured.planned_ad_spend / 100))
    }
  }, [data, spend])

  // Re-forecast as the number is typed, without a request per keystroke.
  useEffect(() => {
    if (spend === null) return
    const handle = setTimeout(() => setDebounced(Math.round(Number(spend || '0') * 100)), 300)
    return () => clearTimeout(handle)
  }, [spend])

  const save = useMutation({
    mutationFn: () => saveAdSpend(projectId, Math.round(Number(spend || '0') * 100)),
  })

  if (isPending || !data || spend === null) {
    return (
      <>
        <PageHeader title="Funding forecast" />
        <Skeleton className="h-72" />
      </>
    )
  }

  const { measured, scenarios, recommended_budget: recommended, focus } = data
  const requirements = Array.isArray(data.requirements) ? null : data.requirements
  const plannedSpend = Math.round(Number(spend || '0') * 100)
  const worstCase = scenarios[0]
  const enough = worstCase?.funds_the_goal ?? false
  const anyEnough = scenarios.some((s) => s.funds_the_goal)
  const shortBy = recommended - plannedSpend

  return (
    <>
      <PageHeader title="Funding forecast" subtitle="What your budget buys, across the outcomes that matter">
        <Badge variant={data.confidence === 'high' ? 'success' : 'outline'}>
          {data.confidence} confidence
        </Badge>
      </PageHeader>

      {focus && (
        <div
          className={cn(
            'mb-4 rounded-lg border-l-2 bg-muted/40 p-4',
            focus.severity === 'critical'
              ? 'border-l-[color:var(--status-critical)]'
              : focus.severity === 'warning'
                ? 'border-l-[color:var(--status-warning)]'
                : 'border-l-[color:var(--viz-series-1)]',
          )}
        >
          <p className="text-sm font-medium">{focus.title}</p>
          <p className="mt-1 text-sm text-muted-foreground">{focus.body}</p>
          <p className="mt-1.5 text-sm">
            <span className="font-medium">Do this:</span> {focus.action}
          </p>
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-5">
        <div className="flex flex-col gap-4 lg:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>Your ad budget</CardTitle>
              <CardDescription>The only thing you need to decide.</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="f-spend">Planned ad spend (£)</Label>
                <Input
                  id="f-spend"
                  type="number"
                  min="0"
                  value={spend}
                  onChange={(e) => setSpend(e.target.value)}
                />
              </div>
              <div className="flex items-center gap-3">
                <Button variant="outline" size="sm" onClick={() => save.mutate()} disabled={save.isPending}>
                  {save.isPending ? 'Saving…' : 'Save budget'}
                </Button>
                {save.isSuccess && (
                  <span role="status" className="text-xs text-muted-foreground">
                    {save.data.message}
                  </span>
                )}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>What we measured</CardTitle>
              <CardDescription>Taken from your own ads — nothing to enter.</CardDescription>
            </CardHeader>
            <CardContent>
              <dl className="flex flex-col gap-2 text-sm">
                <Rated
                  label="Cost per click"
                  value={money(Math.round(measured.cpc * 100))}
                  rating={data.ratings.cpc}
                  estimated={!measured.cpc_measured}
                />
                <Rated
                  label="Visitors who subscribe"
                  value={percent(measured.visitor_to_subscriber_rate * 100)}
                  rating={data.ratings.conversion}
                  estimated={!measured.conversion_measured}
                />
                <div className="flex justify-between gap-3">
                  <dt className="text-muted-foreground">Email list now</dt>
                  <dd className="tabular-nums">{number(measured.email_subscribers)}</dd>
                </div>
                <div className="flex justify-between gap-3">
                  <dt className="text-muted-foreground">Average pledge</dt>
                  <dd className="tabular-nums">{money(measured.average_pledge)}</dd>
                </div>
              </dl>
              {(!measured.cpc_measured || !measured.conversion_measured) && (
                <p className="mt-3 text-xs text-muted-foreground">
                  Figures marked "typical" are pre-launch benchmarks. Run ads and they are replaced by your
                  own numbers.
                </p>
              )}
            </CardContent>
          </Card>
        </div>

        <div className="flex flex-col gap-4 lg:col-span-3">
          <Card>
            <CardContent className="p-6">
              <p className="text-sm text-muted-foreground">
                {enough
                  ? 'Your budget funds the goal even in the worst case'
                  : anyEnough
                    ? 'Your budget funds the goal only if your list converts well'
                    : 'Your budget does not reach the goal'}
              </p>
              <p className="mt-1 text-3xl font-semibold tracking-tight">
                {recommended === 0
                  ? 'No more spend needed'
                  : shortBy > 0
                    ? `${money(shortBy)} short`
                    : 'On track'}
              </p>
              <p className="mt-2 text-sm text-muted-foreground">
                {recommended === 0 ? (
                  <>Your list is already large enough to hit {money(measured.funding_goal)}.</>
                ) : (
                  <>
                    We'd budget <span className="font-medium text-foreground">{money(recommended)}</span> to
                    reach {money(measured.funding_goal)}, assuming{' '}
                    {percent(data.planning_rate * 100, 0)} of your list backs you — the cautious middle of the
                    range below.
                  </>
                )}
              </p>
              {recommended > 0 && shortBy > 0 && (
                <Button className="mt-4" size="sm" onClick={() => setSpend(String(recommended / 100))}>
                  Use the recommended budget
                </Button>
              )}
            </CardContent>
          </Card>

          {requirements && (
            <Card>
              <CardHeader>
                <CardTitle>What has to happen</CardTitle>
                <CardDescription>
                  For {money(plannedSpend)} to fund {money(measured.funding_goal)}.
                </CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                <p className="text-sm">
                  You need <span className="font-medium">{number(requirements.backers_needed)} backers</span>,
                  which means a list of about{' '}
                  <span className="font-medium">{number(requirements.subscribers_needed)}</span> at the
                  {' '}{percent(data.planning_rate * 100, 0)} planning rate. Your budget buys about{' '}
                  {number(requirements.visitors_bought)} visitors.
                </p>

                {requirements.required_conversion && (
                  <Requirement
                    label="Visitors who must subscribe"
                    value={percent(requirements.required_conversion.rate * 100)}
                    detail={`you're at ${percent(requirements.required_conversion.current * 100)}`}
                    likelihood={requirements.required_conversion.likelihood}
                  />
                )}

                {requirements.required_backer_rate && (
                  <Requirement
                    label="Your list who must back"
                    value={percent(requirements.required_backer_rate.rate * 100)}
                    detail={`from a list of ${number(requirements.projected_list)}`}
                    likelihood={requirements.required_backer_rate.likelihood}
                  />
                )}
              </CardContent>
            </Card>
          )}

          <Card>
            <CardHeader>
              <CardTitle>If your list converts at…</CardTitle>
              <CardDescription>
                Nobody knows this number before launching, so here is the whole range.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <caption className="sr-only">
                    Projected backers and funding at each subscriber-to-backer rate
                  </caption>
                  <thead>
                    <tr className="border-b border-border text-left text-xs text-muted-foreground">
                      <th scope="col" className="pb-2 font-medium">Backer rate</th>
                      <th scope="col" className="pb-2 text-right font-medium">Backers</th>
                      <th scope="col" className="pb-2 text-right font-medium">Funding</th>
                      <th scope="col" className="pb-2 text-right font-medium">Of goal</th>
                    </tr>
                  </thead>
                  <tbody>
                    {scenarios.map((scenario) => (
                      <tr key={scenario.label} className="border-b border-border last:border-0">
                        <th scope="row" className="py-2.5 text-left font-medium">
                          <span className="flex items-center gap-1.5">
                            <span
                              aria-hidden
                              className={cn(
                                'flex h-4 w-4 items-center justify-center rounded-full',
                                scenario.funds_the_goal ? 'bg-accent' : 'bg-muted',
                              )}
                            >
                              {scenario.funds_the_goal ? (
                                <Check className="h-3 w-3 text-accent-foreground" />
                              ) : (
                                <X className="h-3 w-3 text-muted-foreground" />
                              )}
                            </span>
                            {scenario.label}
                            <span className="sr-only">
                              {scenario.funds_the_goal ? ': funds the goal' : ': short of the goal'}
                            </span>
                          </span>
                        </th>
                        <td className="py-2.5 text-right tabular-nums">
                          {number(scenario.expected_backers)}
                        </td>
                        <td className="py-2.5 text-right tabular-nums">
                          {money(scenario.expected_funding)}
                        </td>
                        <td className="py-2.5 text-right tabular-nums text-muted-foreground">
                          {percent(scenario.goal_coverage * 100, 0)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="mt-3 text-xs text-muted-foreground">
                A cold list bought cheaply sits near 10%. A list warmed with regular updates before launch can
                reach 30%.
              </p>
            </CardContent>
          </Card>
        </div>
      </div>
    </>
  )
}
