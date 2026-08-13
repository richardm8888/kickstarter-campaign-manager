import { useParams } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { getDashboard } from './api'
import { getProject } from '@/features/projects/api'
import { SetupCard } from './SetupCard'
import { TodayCard } from '@/features/daily/TodayCard'
import { StatCard } from './StatCard'
import { PixelProbe } from '@/features/ads/PixelProbe'
import { Funnel } from './Funnel'
import { PageHeader } from '@/components/layout/ProjectLayout'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { money, number, percent } from '@/lib/format'
import { ApiError } from '@/lib/api'

/** Days-to-launch chip, or a prompt to set a date when none exists. */
function LaunchCountdown({ launchDate, projectId }: { launchDate: string | null; projectId: string }) {
  if (!launchDate) {
    return (
      <Link
        to="/projects/$projectId/settings"
        params={{ projectId }}
        className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
      >
        Set a launch date
      </Link>
    )
  }

  const days = Math.ceil(
    (new Date(launchDate).setHours(0, 0, 0, 0) - new Date().setHours(0, 0, 0, 0)) / 86_400_000,
  )

  return (
    <Link to="/projects/$projectId/settings" params={{ projectId }} className="text-right">
      <p className="text-sm font-medium">
        {days > 0 ? `${days} ${days === 1 ? 'day' : 'days'} to launch` : days === 0 ? 'Launching today' : 'Launched'}
      </p>
      <p className="text-xs text-muted-foreground">
        {new Date(launchDate).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })}
      </p>
    </Link>
  )
}

export function DashboardPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { data, isPending, isError, error } = useQuery(getDashboard(projectId))
  const { data: project } = useQuery(getProject(projectId))

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
    // Say what actually happened. "Try refreshing" is useless advice
    // against a proxy that cannot reach the app, and it hid exactly
    // that for an evening.
    const reason = error instanceof ApiError ? error.message : 'Try refreshing.'

    return (
      <p role="alert" className="text-sm text-destructive">
        Couldn't load the dashboard. {reason}
      </p>
    )
  }

  const { setup, cards, funnel, currency } = data
  const forecast = cards.funding_forecast

  return (
    <>
      <PageHeader title="Dashboard" subtitle="The state of your launch at a glance">
        <LaunchCountdown launchDate={project?.project.launch_date ?? null} projectId={projectId} />
      </PageHeader>

      {/* First, deliberately. This started at the foot of the Ads page,
          which on a phone is below every ad and the turned-off
          accordion; moved to sit under the daily card it was still more
          than a screen down. It is a one-off question — once Meta has
          answered it, this belongs back with the ad diagnostics. */}
      <div className="mb-6">
        <PixelProbe projectId={projectId} />
      </div>

      <SetupCard setup={setup} projectId={projectId} />

      <TodayCard projectId={projectId} />

      <section aria-label="Key metrics" className="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
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
        <StatCard
          label="Kickstarter followers"
          value={number(cards.ks_followers.value ?? 0)}
          hint="Notified at launch — worth ~10 email signups each"
        />
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
          <CardDescription>
            Ads over the last {funnel.window_days} days, then your audience to date
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Funnel funnel={funnel} currency={currency} />
        </CardContent>
      </Card>
    </>
  )
}
