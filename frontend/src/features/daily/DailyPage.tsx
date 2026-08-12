import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowRight, ArrowUp, Check, Clock, X } from 'lucide-react'
import { getDailyBrief, getDailyHistory, setTaskStatus } from './api'
import { getProject } from '@/features/projects/api'
import { PageHeader } from '@/components/layout/ProjectLayout'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import type { DailyPriority, DailyTask, FunnelHealthRow } from '@/lib/types'

const PRIORITY: Record<DailyPriority, { label: string; dot: string }> = {
  high: { label: 'HIGH', dot: 'var(--status-critical)' },
  medium: { label: 'MEDIUM', dot: 'var(--status-warning)' },
  low: { label: 'LOW', dot: 'var(--status-good)' },
}

function formatValue(row: FunnelHealthRow, currency: string): string {
  if (row.format === 'percent') return `${row.value}%`
  if (row.format === 'money') {
    return new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(row.value)
  }

  return row.value.toLocaleString('en-GB')
}

/**
 * Direction is shown, never coloured green or red. Whether a rise is good
 * depends on the metric — cost going up is bad, list going up is good —
 * and a wrong colour is read faster than a right number.
 */
function Direction({ direction }: { direction: FunnelHealthRow['direction'] }) {
  if (direction === 'unknown') return null

  const Icon = direction === 'up' ? ArrowUp : direction === 'down' ? ArrowDown : ArrowRight

  return <Icon className="h-3.5 w-3.5 text-muted-foreground" aria-label={direction} />
}

export function DailyPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { data, isPending } = useQuery(getDailyBrief(projectId))
  const { data: project } = useQuery(getProject(projectId))
  const [showHistory, setShowHistory] = useState(false)

  if (isPending || !data) {
    return (
      <>
        <PageHeader title="Today" />
        <Skeleton className="h-96" />
      </>
    )
  }

  const currency = project?.project.currency ?? 'GBP'

  return (
    <>
      <PageHeader
        title="Today"
        subtitle={
          data.tasks.length === 0
            ? 'Nothing urgent today.'
            : `${data.tasks.length} ${data.tasks.length === 1 ? 'thing' : 'things'} worth doing, most valuable first`
        }
      />

      <div className="flex flex-col gap-4">
        {data.tasks.length === 0 ? (
          <Card>
            <CardContent className="py-8 text-center">
              <p className="text-sm font-medium">Nothing urgent today.</p>
              <p className="mt-1 text-sm text-muted-foreground">
                Nothing in your numbers needs a decision. Spend the time on the game.
              </p>
            </CardContent>
          </Card>
        ) : (
          data.tasks.map((task) => <TaskCard key={task.id} projectId={projectId} task={task} />)
        )}

        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Funnel health</CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="flex flex-col gap-2">
                {data.funnel_health.map((row) => (
                  <div key={row.key} className="flex items-center justify-between gap-4 text-sm">
                    <dt className="text-muted-foreground">{row.label}</dt>
                    <dd className="flex items-center gap-1.5 font-medium tabular-nums">
                      {formatValue(row, currency)}
                      <Direction direction={row.direction} />
                    </dd>
                  </div>
                ))}
              </dl>
            </CardContent>
          </Card>

          {data.nothing_to_worry_about.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>Nothing to worry about</CardTitle>
                <CardDescription>Checked today and fine. Ignore these.</CardDescription>
              </CardHeader>
              <CardContent>
                <ul className="flex flex-col gap-2">
                  {data.nothing_to_worry_about.map((note) => (
                    <li key={note} className="flex gap-2 text-sm text-muted-foreground">
                      <Check
                        className="mt-0.5 h-4 w-4 shrink-0 text-[color:var(--status-good)]"
                        aria-hidden
                      />
                      {note}
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          )}
        </div>

        <div>
          <Button variant="ghost" size="sm" onClick={() => setShowHistory((open) => !open)}>
            {showHistory ? 'Hide' : 'Show'} what was recommended before
          </Button>
          {showHistory && <History projectId={projectId} />}
        </div>
      </div>
    </>
  )
}

function TaskCard({ projectId, task }: { projectId: string; task: DailyTask }) {
  const queryClient = useQueryClient()
  const priority = PRIORITY[task.priority]

  const mutation = useMutation({
    mutationFn: (status: 'done' | 'dismissed') => setTaskStatus(projectId, task.id, status),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'daily'] })
    },
  })

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-wrap items-center gap-2">
          <span
            aria-hidden
            className="h-2.5 w-2.5 shrink-0 rounded-full"
            style={{ backgroundColor: priority.dot }}
          />
          <span className="text-xs font-semibold tracking-wide text-muted-foreground">
            {priority.label}
          </span>
          <span className="flex items-center gap-1 text-xs text-muted-foreground">
            <Clock className="h-3 w-3" aria-hidden />
            {task.effort_minutes} min
          </span>
        </div>
        <CardTitle className="mt-1">{task.title}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <div>
          <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Why</h3>
          <p className="mt-0.5 text-sm text-muted-foreground">{task.why}</p>
        </div>
        <div>
          <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Do</h3>
          <p className="mt-0.5 text-sm">{task.action}</p>
        </div>
        <div className="flex flex-wrap gap-2 pt-1">
          <Button size="sm" disabled={mutation.isPending} onClick={() => mutation.mutate('done')}>
            <Check className="h-4 w-4" aria-hidden />
            Done
          </Button>
          <Button
            size="sm"
            variant="ghost"
            disabled={mutation.isPending}
            onClick={() => mutation.mutate('dismissed')}
          >
            <X className="h-4 w-4" aria-hidden />
            Not worth it
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

function History({ projectId }: { projectId: string }) {
  const { data } = useQuery(getDailyHistory(projectId))
  const tasks = data?.tasks ?? []

  if (tasks.length === 0) {
    return <p className="mt-3 text-sm text-muted-foreground">Nothing recommended before today yet.</p>
  }

  return (
    <ul className="mt-3 flex flex-col gap-2">
      {tasks.map((task) => (
        <li
          key={task.id}
          className="flex flex-wrap items-baseline justify-between gap-2 border-b border-border pb-2 text-sm"
        >
          <span className={cn(task.status === 'done' && 'text-muted-foreground line-through')}>
            {task.title}
          </span>
          <span className="text-xs text-muted-foreground">
            {new Date(task.for_date).toLocaleDateString('en-GB')} ·{' '}
            {task.status === 'done' ? 'done' : task.status === 'dismissed' ? 'skipped' : 'still open'}
          </span>
        </li>
      ))}
    </ul>
  )
}
