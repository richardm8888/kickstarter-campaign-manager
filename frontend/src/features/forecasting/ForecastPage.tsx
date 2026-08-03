import { useEffect, useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQuery } from '@tanstack/react-query'
import { getForecast, previewForecast, saveAssumptions } from './api'
import { PageHeader } from '@/components/layout/ProjectLayout'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { money, number, percent } from '@/lib/format'
import { Button } from '@/components/ui/button'
import type { Forecast, ForecastInput } from '@/lib/types'

interface Assumptions {
  planned_ad_spend: string
  cpc: string
  visitor_to_subscriber_rate: string
  subscriber_to_backer_rate: string
  average_pledge: string
}

export function ForecastPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const base = useQuery(getForecast(projectId))

  const [assumptions, setAssumptions] = useState<Assumptions | null>(null)
  const [preview, setPreview] = useState<Forecast | null>(null)

  const save = useMutation({
    mutationFn: (input: Partial<ForecastInput>) => saveAssumptions(projectId, input),
  })

  useEffect(() => {
    if (base.data && assumptions === null) {
      const input = base.data.input
      setAssumptions({
        planned_ad_spend: String(input.planned_ad_spend / 100),
        cpc: String(input.cpc),
        visitor_to_subscriber_rate: String(Math.round(input.visitor_to_subscriber_rate * 100)),
        subscriber_to_backer_rate: String(Math.round(input.subscriber_to_backer_rate * 100)),
        average_pledge: String(input.average_pledge / 100),
      })
    }
  }, [base.data, assumptions])

  const payload = useMemo(() => {
    if (!assumptions) return null
    return {
      planned_ad_spend: Math.round(Number(assumptions.planned_ad_spend || '0') * 100),
      cpc: Number(assumptions.cpc || '0.01'),
      visitor_to_subscriber_rate: Number(assumptions.visitor_to_subscriber_rate || '0') / 100,
      subscriber_to_backer_rate: Number(assumptions.subscriber_to_backer_rate || '0') / 100,
      average_pledge: Math.round(Number(assumptions.average_pledge || '0') * 100),
    }
  }, [assumptions])

  // Debounced what-if preview as the creator edits assumptions.
  useEffect(() => {
    if (!payload) return
    const handle = setTimeout(() => {
      previewForecast(projectId, payload).then((r) => setPreview(r.forecast)).catch(() => {})
    }, 300)
    return () => clearTimeout(handle)
  }, [projectId, payload])

  if (base.isPending || !assumptions) {
    return (
      <>
        <PageHeader title="Funding forecast" />
        <Skeleton className="h-72" />
      </>
    )
  }

  const forecast = preview ?? base.data?.forecast
  if (!forecast) return null

  const set = (key: keyof Assumptions) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setAssumptions((a) => (a ? { ...a, [key]: e.target.value } : a))

  return (
    <>
      <PageHeader title="Funding forecast" subtitle="Deterministic model — same inputs, same answer">
        <Badge variant={forecast.confidence === 'high' ? 'success' : 'outline'}>
          {forecast.confidence} confidence
        </Badge>
      </PageHeader>

      <div className="grid gap-4 lg:grid-cols-5">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Assumptions</CardTitle>
            <CardDescription>Pre-filled from observed data where available</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="f-spend">Planned ad spend (£)</Label>
              <Input id="f-spend" type="number" min="0" value={assumptions.planned_ad_spend} onChange={set('planned_ad_spend')} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="f-cpc">Cost per click (£)</Label>
              <Input id="f-cpc" type="number" min="0.01" step="0.05" value={assumptions.cpc} onChange={set('cpc')} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="f-conv">Visitor → subscriber (%)</Label>
              <Input id="f-conv" type="number" min="0" max="100" value={assumptions.visitor_to_subscriber_rate} onChange={set('visitor_to_subscriber_rate')} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="f-backer">Subscriber → backer (%)</Label>
              <Input id="f-backer" type="number" min="0" max="100" value={assumptions.subscriber_to_backer_rate} onChange={set('subscriber_to_backer_rate')} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="f-pledge">Average pledge (£)</Label>
              <Input id="f-pledge" type="number" min="0" value={assumptions.average_pledge} onChange={set('average_pledge')} />
            </div>
            <div className="flex items-center gap-3">
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={!payload || save.isPending}
                onClick={() => payload && save.mutate(payload)}
              >
                {save.isPending ? 'Saving…' : 'Save as defaults'}
              </Button>
              {save.isSuccess && (
                <span role="status" className="text-xs text-muted-foreground">
                  {save.data.message}
                </span>
              )}
            </div>
          </CardContent>
        </Card>

        <div className="flex flex-col gap-4 lg:col-span-3">
          <Card>
            <CardContent className="p-6">
              <p className="text-sm text-muted-foreground">Expected funding</p>
              <p className="mt-1 text-4xl font-semibold tracking-tight">
                {money(forecast.expected_funding)}
              </p>
              <div className="mt-3 h-2 overflow-hidden rounded-full bg-muted" aria-hidden>
                <div
                  className="h-full rounded-full bg-primary"
                  style={{ width: `${Math.min(100, forecast.goal_coverage * 100)}%` }}
                />
              </div>
              <p className="mt-1.5 text-sm text-muted-foreground">
                {percent(forecast.goal_coverage * 100, 0)} of your {money(forecast.funding_goal)} goal
              </p>
            </CardContent>
          </Card>

          <div className="grid grid-cols-2 gap-4">
            <Card>
              <CardContent className="p-5">
                <p className="text-sm text-muted-foreground">Projected visitors</p>
                <p className="mt-1 text-xl font-semibold tabular-nums">{number(forecast.projected_visitors)}</p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-5">
                <p className="text-sm text-muted-foreground">Projected subscribers</p>
                <p className="mt-1 text-xl font-semibold tabular-nums">{number(forecast.projected_subscribers)}</p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-5">
                <p className="text-sm text-muted-foreground">Projected VIPs</p>
                <p className="mt-1 text-xl font-semibold tabular-nums">{number(forecast.projected_vips)}</p>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-5">
                <p className="text-sm text-muted-foreground">Expected backers</p>
                <p className="mt-1 text-xl font-semibold tabular-nums">{number(forecast.expected_backers)}</p>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </>
  )
}
