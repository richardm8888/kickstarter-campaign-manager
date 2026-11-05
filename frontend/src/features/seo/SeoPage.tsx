import { useState, type ReactNode } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, ListPlus, RefreshCw, Sparkles } from 'lucide-react'
import { getRecommendations, getSeo, seoKey, type SeoRecommendation, type SeoRow, type SeoTotals } from './api'
import { createTask, launchPlanKey } from '@/features/launch/api'
import { PageHeader } from '@/components/layout/ProjectLayout'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { ApiError } from '@/lib/api'

/** Page paths read better without the domain repeated on every row. */
function path(url: string): string {
  try {
    const pathname = new URL(url).pathname
    return pathname === '/' || pathname === '' ? 'Homepage' : pathname
  } catch {
    return url
  }
}

const number = (value: number) => value.toLocaleString('en-GB')

function Change({ now, before, lowerIsBetter = false, suffix = '' }: {
  now: number | null
  before: number | null
  lowerIsBetter?: boolean
  suffix?: string
}) {
  if (now === null || before === null) return <span className="text-xs text-muted-foreground">no earlier data</span>
  const diff = Math.round((now - before) * 10) / 10
  if (diff === 0) return <span className="text-xs text-muted-foreground">no change</span>

  const better = lowerIsBetter ? diff < 0 : diff > 0
  const words = lowerIsBetter
    ? `${better ? 'up' : 'down'} ${Math.abs(diff)} places`
    : `${diff > 0 ? '+' : ''}${diff.toLocaleString('en-GB')}${suffix}`

  return <span className="text-xs text-muted-foreground">{words} vs previous period</span>
}

function Tile({ label, children, change }: { label: string; children: ReactNode; change: ReactNode }) {
  return (
    <Card>
      <CardContent className="flex flex-col gap-1 p-4">
        <span className="text-xs text-muted-foreground">{label}</span>
        <span className="text-2xl font-semibold tabular-nums">{children}</span>
        {change}
      </CardContent>
    </Card>
  )
}

function Section({ title, description, empty, children }: {
  title: string
  description: string
  empty: boolean
  children: ReactNode
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
      <CardContent>
        {empty ? <p className="text-sm text-muted-foreground">Nothing here for this period.</p> : children}
      </CardContent>
    </Card>
  )
}

/** A compact row that reads on a phone: the search on top, its numbers under it. */
function Row({ title, detail, children }: { title: string; detail?: string; children: ReactNode }) {
  return (
    <li className="flex flex-col gap-0.5 border-b border-border py-2 last:border-0">
      <span className="text-sm font-medium">{title}</span>
      {detail && <span className="truncate text-xs text-muted-foreground">{detail}</span>}
      <span className="text-xs text-muted-foreground tabular-nums">{children}</span>
    </li>
  )
}

const stats = (row: { impressions: number; clicks: number; ctr: number; position: number }) =>
  `${number(row.impressions)} impressions · ${number(row.clicks)} clicks · ${row.ctr}% CTR · position ${row.position}`

export function SeoPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const queryClient = useQueryClient()
  const [days, setDays] = useState(90)
  const { data, error, isPending, isFetching } = useQuery(getSeo(projectId, days))

  const refresh = useMutation({
    mutationFn: () => queryClient.fetchQuery({ ...getSeo(projectId, days, true), staleTime: 0 }),
    onSuccess: (fresh) => queryClient.setQueryData(seoKey(projectId, days), fresh),
  })

  const header = (
    <PageHeader
      title="Search (SEO)"
      subtitle={
        data?.analysis
          ? `Google Search Console, ${data.analysis.window.start} to ${data.analysis.window.end}`
          : 'How your website does in Google search'
      }
    >
      {data?.connected && (
        <div className="flex flex-wrap gap-2">
          {[28, 90].map((window) => (
            <Button key={window} size="sm" variant={days === window ? 'default' : 'secondary'} onClick={() => setDays(window)}>
              {window} days
            </Button>
          ))}
          <Button size="sm" variant="outline" disabled={refresh.isPending || isFetching} onClick={() => refresh.mutate()}>
            <RefreshCw className="h-4 w-4" aria-hidden />
            Refresh
          </Button>
        </div>
      )}
    </PageHeader>
  )

  if (isPending) {
    return (
      <>
        {header}
        <Skeleton className="h-96" />
      </>
    )
  }

  if (error) {
    return (
      <>
        {header}
        <Card>
          <CardContent className="py-6 text-sm">
            <p className="font-medium">Couldn't read Search Console</p>
            <p className="mt-1 text-muted-foreground">{error instanceof ApiError ? error.message : 'Something went wrong.'}</p>
          </CardContent>
        </Card>
      </>
    )
  }

  if (!data?.connected || !data.analysis) {
    return (
      <>
        {header}
        <Card>
          <CardHeader>
            <CardTitle>Connect Google Search Console</CardTitle>
            <CardDescription>See which searches find your site, where you are close to page one, and what to fix first.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-3 text-sm">
            <ol className="ml-5 list-decimal space-y-1 text-muted-foreground">
              <li>In Google Cloud, enable the <strong>Search Console API</strong> for the project that holds your service account (the GA4 one works).</li>
              <li>In Search Console → Settings → Users and permissions, add the service account's <code>client_email</code> with Restricted access.</li>
              <li>On Integrations, connect Google Search Console with your property name (for example <code>sc-domain:yoursite.com</code>) and the key.</li>
            </ol>
            <div>
              <Link
                to="/projects/$projectId/integrations"
                params={{ projectId }}
                className="inline-flex h-9 items-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
              >
                Go to Integrations
              </Link>
            </div>
          </CardContent>
        </Card>
      </>
    )
  }

  const { analysis } = data
  const { current, previous } = analysis.summary as { current: SeoTotals; previous: SeoTotals }

  return (
    <>
      {header}
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Tile label="Clicks" change={<Change now={current.clicks} before={previous.clicks} />}>
            {number(current.clicks)}
          </Tile>
          <Tile label="Impressions" change={<Change now={current.impressions} before={previous.impressions} />}>
            {number(current.impressions)}
          </Tile>
          <Tile label="Click-through rate" change={<Change now={current.ctr} before={previous.ctr} suffix=" pts" />}>
            {current.ctr}%
          </Tile>
          <Tile label="Average position" change={<Change now={current.position} before={previous.position} lowerIsBetter />}>
            {current.position ?? '–'}
          </Tile>
        </div>

        <Recommendations projectId={projectId} days={days} aiAvailable={data.ai_available} />

        <Section
          title="Close to page one"
          description="Searches where you rank 6–20. You already rank, so a sharper title, a dedicated section or a few links usually moves these up. The cheapest wins."
          empty={analysis.striking_distance.length === 0}
        >
          <ul>
            {analysis.striking_distance.map((row) => (
              <Row key={`${row.query}-${row.page}`} title={row.query} detail={path(row.page)}>
                {stats(row)}
              </Row>
            ))}
          </ul>
        </Section>

        <Section
          title="Seen but not clicked"
          description="Page-one results clicked far less than results usually are at that position. The title or description isn't earning the click."
          empty={analysis.low_ctr.length === 0}
        >
          <ul>
            {analysis.low_ctr.map((row) => (
              <Row key={row.key} title={row.key}>
                {stats(row)} · typical {row.expected_ctr}% · about {row.missed_clicks} clicks missed
              </Row>
            ))}
          </ul>
        </Section>

        <Section
          title="Searches split across pages"
          description="Several of your pages compete for the same search, so Google isn't sure which to rank. Pick one and link to it from the others."
          empty={analysis.cannibalization.length === 0}
        >
          <ul>
            {analysis.cannibalization.map((row) => (
              <Row
                key={row.query}
                title={row.query}
                detail={row.pages.map((p) => `${path(p.page)} (${number(p.impressions)}, pos ${p.position})`).join(' · ')}
              >
                {number(row.impressions)} impressions in total
              </Row>
            ))}
          </ul>
        </Section>

        <Section
          title="What moved"
          description="Compared with the previous period of the same length."
          empty={Object.values(analysis.movers).every((list) => list.length === 0)}
        >
          <div className="grid gap-4 md:grid-cols-2">
            <MoverList title="Moving up" rows={analysis.movers.risers} kind="change" />
            <MoverList title="Slipping" rows={analysis.movers.fallers} kind="change" />
            <MoverList title="New searches" rows={analysis.movers.new} kind="stats" />
            <MoverList title="No longer appearing for" rows={analysis.movers.lost} kind="stats" />
          </div>
        </Section>

        <div className="grid gap-4 lg:grid-cols-2">
          <Section title="Top searches" description="By impressions." empty={analysis.queries.length === 0}>
            <ul>
              {analysis.queries.map((row) => (
                <Row key={row.key} title={row.key}>
                  {stats(row)}
                </Row>
              ))}
            </ul>
          </Section>
          <Section title="Top pages" description="By impressions." empty={analysis.pages.length === 0}>
            <ul>
              {analysis.pages.map((row) => (
                <Row key={row.key} title={path(row.key)}>
                  {stats(row)}
                </Row>
              ))}
            </ul>
          </Section>
        </div>
      </div>
    </>
  )
}

function MoverList({ title, rows, kind }: { title: string; rows: SeoRow[]; kind: 'change' | 'stats' }) {
  if (rows.length === 0) return null

  return (
    <div>
      <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</h3>
      <ul>
        {rows.map((row) => (
          <Row key={row.key} title={row.key}>
            {kind === 'change'
              ? `position ${row.previous_position} → ${row.position} · ${number(row.impressions)} impressions`
              : stats(row)}
          </Row>
        ))}
      </ul>
    </div>
  )
}

/**
 * The AI's reading of the numbers, as tasks. Each one can go straight
 * into the launch plan, where "Write it for me" can draft it.
 */
function Recommendations({ projectId, days, aiAvailable }: { projectId: string; days: number; aiAvailable: boolean }) {
  const queryClient = useQueryClient()
  const [added, setAdded] = useState<Set<number>>(new Set())

  const recommend = useMutation({
    mutationFn: () => getRecommendations(projectId, days),
    onSuccess: () => setAdded(new Set()),
  })

  const add = useMutation({
    mutationFn: ({ rec }: { rec: SeoRecommendation; index: number }) =>
      createTask(projectId, {
        title: rec.title,
        phase: 'audience',
        category: rec.category,
        content_type: rec.content_type,
        details: `${rec.action}\n\nWhy: ${rec.why}`,
      }),
    onSuccess: (_, { index }) => {
      setAdded((set) => new Set(set).add(index))
      queryClient.invalidateQueries({ queryKey: launchPlanKey(projectId) })
    },
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle>What to do next</CardTitle>
        <CardDescription>
          Recommendations from the numbers on this page, most valuable first. Add the ones you agree with to your launch plan.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {!aiAvailable ? (
          <p className="rounded-lg bg-muted p-3 text-xs text-muted-foreground">
            AI recommendations are switched off on this server. Add <code>ANTHROPIC_API_KEY</code> to the backend
            environment to turn them on. Everything else on this page works without it.
          </p>
        ) : (
          <div className="flex flex-wrap items-center gap-2">
            <Button disabled={recommend.isPending} onClick={() => recommend.mutate()}>
              <Sparkles className="h-4 w-4" aria-hidden />
              {recommend.isPending ? 'Reading the numbers…' : recommend.data ? 'Get fresh recommendations' : 'Get recommendations'}
            </Button>
            {recommend.error && (
              <span className="text-xs text-destructive">
                {recommend.error instanceof ApiError ? recommend.error.message : 'Something went wrong.'}
              </span>
            )}
          </div>
        )}

        {recommend.data?.recommendations.map((rec, index) => (
          <div key={`${rec.title}-${index}`} className="flex flex-col gap-1.5 rounded-lg border border-border p-3">
            <div className="flex flex-wrap items-center gap-2">
              <Badge variant={rec.priority === 'high' ? 'warning' : 'default'}>{rec.priority}</Badge>
              <span className="text-sm font-medium">{rec.title}</span>
            </div>
            <p className="text-sm">{rec.action}</p>
            <p className="text-xs text-muted-foreground">{rec.why}</p>
            <div>
              <Button
                size="sm"
                variant="outline"
                disabled={added.has(index) || add.isPending}
                onClick={() => add.mutate({ rec, index })}
              >
                {added.has(index) ? <Check className="h-4 w-4" aria-hidden /> : <ListPlus className="h-4 w-4" aria-hidden />}
                {added.has(index) ? 'Added to launch plan' : 'Add to launch plan'}
              </Button>
            </div>
          </div>
        ))}
      </CardContent>
    </Card>
  )
}
