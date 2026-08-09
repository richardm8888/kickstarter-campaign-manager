import { Link } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { Clock } from 'lucide-react'
import { getDailyBrief } from './api'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import type { DailyPriority } from '@/lib/types'

const DOT: Record<DailyPriority, string> = {
  high: 'var(--status-critical)',
  medium: 'var(--status-warning)',
  low: 'var(--status-good)',
}

/**
 * The dashboard's answer to "what should I do now".
 *
 * Shows only the top task and a count of the rest. The full list is one
 * click away, and putting all three here would turn the thing designed to
 * cut through a wall of numbers into part of the wall.
 */
export function TodayCard({ projectId }: { projectId: string }) {
  const { data } = useQuery(getDailyBrief(projectId))

  if (!data) return null

  const [first, ...rest] = data.tasks

  return (
    <Card>
      <CardHeader>
        <CardTitle>Today</CardTitle>
        <CardDescription>
          {first
            ? 'The most valuable thing you could do right now'
            : 'Nothing in your numbers needs a decision today'}
        </CardDescription>
      </CardHeader>
      <CardContent>
        {first ? (
          <Link
            to="/projects/$projectId/today"
            params={{ projectId }}
            className="block rounded-md p-2 -m-2 transition-colors hover:bg-accent"
          >
            <div className="flex flex-wrap items-center gap-2">
              <span
                aria-hidden
                className="h-2.5 w-2.5 shrink-0 rounded-full"
                style={{ backgroundColor: DOT[first.priority] }}
              />
              <span className="text-xs font-semibold tracking-wide text-muted-foreground">
                {first.priority.toUpperCase()}
              </span>
              <span className="flex items-center gap-1 text-xs text-muted-foreground">
                <Clock className="h-3 w-3" aria-hidden />
                {first.effort_minutes} min
              </span>
            </div>
            <p className="mt-1 text-sm font-medium">{first.title}</p>
            <p className="mt-0.5 text-sm text-muted-foreground">{first.why}</p>
            {rest.length > 0 && (
              <p className="mt-2 text-xs text-muted-foreground">
                and {rest.length} more →
              </p>
            )}
          </Link>
        ) : (
          <p className="text-sm text-muted-foreground">
            Spend the time on the game.{' '}
            <Link
              to="/projects/$projectId/today"
              params={{ projectId }}
              className="underline underline-offset-2"
            >
              See what was checked
            </Link>
            .
          </p>
        )}
      </CardContent>
    </Card>
  )
}
