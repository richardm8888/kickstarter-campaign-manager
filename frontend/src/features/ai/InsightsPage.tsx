import { useParams } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Brain, Check, Sparkles } from 'lucide-react'
import { acknowledgeInsight, generateInsights, listInsights } from './api'
import { EmptyState, PageHeader } from '@/components/layout/ProjectLayout'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import type { Insight } from '@/lib/types'

const SEVERITY: Record<Insight['severity'], { label: string; variant: 'default' | 'success' | 'warning' | 'destructive' }> = {
  info: { label: 'Info', variant: 'default' },
  success: { label: 'Good news', variant: 'success' },
  warning: { label: 'Warning', variant: 'warning' },
  critical: { label: 'Critical', variant: 'destructive' },
}

export function InsightsPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const queryClient = useQueryClient()
  const { data, isPending } = useQuery(listInsights(projectId))

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'insights'] })

  const generate = useMutation({ mutationFn: () => generateInsights(projectId), onSuccess: invalidate })
  const acknowledge = useMutation({
    mutationFn: (insightId: number) => acknowledgeInsight(projectId, insightId),
    onSuccess: invalidate,
  })

  const open = data?.insights.filter((i) => !i.acknowledged_at) ?? []

  return (
    <>
      <PageHeader title="AI insights" subtitle="Recommendations, not raw data">
        <Button onClick={() => generate.mutate()} disabled={generate.isPending}>
          <Sparkles className="h-4 w-4" aria-hidden />
          {generate.isPending ? 'Analysing…' : 'Analyse now'}
        </Button>
      </PageHeader>

      {isPending ? (
        <div className="flex flex-col gap-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
      ) : open.length === 0 ? (
        <EmptyState
          icon={Brain}
          title="No open insights"
          body="Run an analysis, or wait for the next hourly sync — new signals appear here automatically."
        />
      ) : (
        <div className="flex flex-col gap-3">
          {open.map((insight) => {
            const severity = SEVERITY[insight.severity] ?? SEVERITY.info
            return (
              <Card key={insight.id}>
                <CardContent className="p-5">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={severity.variant}>{severity.label}</Badge>
                        {insight.kind === 'recommendation' && <Badge variant="outline">Recommendation</Badge>}
                      </div>
                      <p className="mt-2 font-medium">{insight.title}</p>
                      <p className="mt-1 text-sm text-muted-foreground">{insight.body}</p>
                      {insight.action && insight.action !== insight.body && (
                        <p className="mt-2 text-sm">
                          <span className="font-medium">Next step:</span> {insight.action}
                        </p>
                      )}
                    </div>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => acknowledge.mutate(insight.id)}
                      aria-label={`Dismiss: ${insight.title}`}
                    >
                      <Check className="h-4 w-4" aria-hidden />
                      Done
                    </Button>
                  </div>
                </CardContent>
              </Card>
            )
          })}
        </div>
      )}
    </>
  )
}
