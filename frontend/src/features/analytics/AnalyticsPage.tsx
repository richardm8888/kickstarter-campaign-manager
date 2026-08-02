import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { getAnalytics, type AnalyticsCategory } from './api'
import { MetricChart } from './MetricChart'
import { EmptyState, PageHeader } from '@/components/layout/ProjectLayout'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { number } from '@/lib/format'

const CATEGORIES: { key: AnalyticsCategory; label: string }[] = [
  { key: 'traffic', label: 'Traffic' },
  { key: 'ads', label: 'Ads' },
  { key: 'email', label: 'Email' },
  { key: 'revenue', label: 'Revenue' },
]

const RANGES = [7, 30, 90] as const

export function AnalyticsPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const [category, setCategory] = useState<AnalyticsCategory>('traffic')
  const [days, setDays] = useState<number>(30)

  const { data, isPending } = useQuery(getAnalytics(projectId, category, days))

  return (
    <>
      <PageHeader title="Analytics" subtitle="Time series from your connected integrations" />

      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div role="tablist" aria-label="Category" className="flex rounded-lg bg-muted p-1">
          {CATEGORIES.map((c) => (
            <button
              key={c.key}
              role="tab"
              aria-selected={category === c.key}
              onClick={() => setCategory(c.key)}
              className={cn(
                'rounded-md px-3 py-1.5 text-sm transition-colors',
                category === c.key
                  ? 'bg-card font-medium shadow-sm'
                  : 'text-muted-foreground hover:text-foreground',
              )}
            >
              {c.label}
            </button>
          ))}
        </div>
        <div className="flex gap-1" role="group" aria-label="Date range">
          {RANGES.map((range) => (
            <button
              key={range}
              onClick={() => setDays(range)}
              aria-pressed={days === range}
              className={cn(
                'rounded-md px-2.5 py-1 text-xs transition-colors',
                days === range ? 'bg-muted font-medium' : 'text-muted-foreground hover:text-foreground',
              )}
            >
              {range}d
            </button>
          ))}
        </div>
      </div>

      {isPending ? (
        <div className="grid gap-4 md:grid-cols-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-60" />
          ))}
        </div>
      ) : data && data.metrics.every((m) => m.series.length === 0) ? (
        <EmptyState
          title="No data in this category yet"
          body="Connect the matching integration and data will start flowing in within the hour."
        />
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {data?.metrics.map((metric) => (
            <Card key={metric.metric}>
              <CardHeader className="flex-row items-baseline justify-between">
                <CardTitle>{metric.label}</CardTitle>
                <span className="text-sm tabular-nums text-muted-foreground">
                  {metric.total > 0 ? number(Math.round(metric.total * 100) / 100) : metric.latest ?? '—'}
                </span>
              </CardHeader>
              <CardContent>
                <MetricChart series={metric.series} label={metric.label} />
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </>
  )
}
