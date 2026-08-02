import { useParams } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { getDashboard } from './api'
import { StatCard } from './StatCard'
import { Funnel } from './Funnel'
import { PageHeader } from '@/components/layout/ProjectLayout'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { money, number, percent } from '@/lib/format'

export function DashboardPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { data, isPending, isError } = useQuery(getDashboard(projectId))

  if (isPending) {
    return (
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        {Array.from({ length: 8 }).map((_, i) => (
          <Skeleton key={i} className="h-28" />
        ))}
      </div>
    )
  }

  if (isError || !data) {
    return <p role="alert" className="text-sm text-destructive">Couldn't load the dashboard. Try refreshing.</p>
  }

  const { cards, funnel, currency } = data
  const forecast = cards.funding_forecast

  return (
    <>
      <PageHeader title="Dashboard" subtitle="The state of your launch at a glance" />

      <section aria-label="Key metrics" className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Visitors (30d)" value={number(cards.visitors.value ?? 0)} change={cards.visitors.change} />
        <StatCard label="Email subscribers" value={number(cards.email_subscribers.value ?? 0)} />
        <StatCard label="VIP upgrades" value={number(cards.vip_upgrades.value ?? 0)} />
        <StatCard
          label="Conversion rate"
          value={cards.conversion_rate.value !== null ? percent(cards.conversion_rate.value) : '—'}
          change={cards.conversion_rate.change}
        />
        <StatCard
          label="Cost per subscriber"
          value={cards.cac.value !== null ? money(cards.cac.value, currency) : '—'}
          hint={cards.cac.value === null ? 'Needs ad spend data' : undefined}
        />
        <StatCard label="Revenue (30d)" value={money(cards.revenue.value ?? 0, currency)} />
        <StatCard label="Projected backers" value={number(cards.projected_backers.value ?? 0)} />
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between">
              <p className="text-sm text-muted-foreground">Funding forecast</p>
              <Badge
                variant={forecast.confidence === 'high' ? 'success' : forecast.confidence === 'medium' ? 'default' : 'outline'}
              >
                {forecast.confidence} confidence
              </Badge>
            </div>
            <p className="mt-1.5 text-2xl font-semibold tracking-tight">{money(forecast.value, currency)}</p>
            <div className="mt-2" aria-hidden>
              <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                  className="h-full rounded-full bg-primary"
                  style={{ width: `${Math.min(100, forecast.coverage * 100)}%` }}
                />
              </div>
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
              {percent(forecast.coverage * 100, 0)} of your {money(forecast.goal, currency)} goal
            </p>
          </CardContent>
        </Card>
      </section>

      <Card className="mt-6">
        <CardHeader>
          <CardTitle>Launch funnel</CardTitle>
          <CardDescription>Last 30 days, from ad click to projected backer</CardDescription>
        </CardHeader>
        <CardContent>
          <Funnel steps={funnel} />
        </CardContent>
      </Card>
    </>
  )
}
