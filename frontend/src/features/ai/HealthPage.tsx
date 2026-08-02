import { useParams } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, CircleAlert } from 'lucide-react'
import { getHealth } from './api'
import { PageHeader } from '@/components/layout/ProjectLayout'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'

function scoreColor(score: number): string {
  if (score >= 70) return 'var(--status-good)'
  if (score >= 40) return 'var(--status-warning)'
  return 'var(--status-critical)'
}

/** 0–100 ring. Color is reinforced by the numeric label — never color alone. */
function ScoreRing({ score }: { score: number }) {
  const radius = 52
  const circumference = 2 * Math.PI * radius

  return (
    <div className="relative h-32 w-32" role="img" aria-label={`Campaign health score ${score} out of 100`}>
      <svg viewBox="0 0 120 120" className="h-full w-full -rotate-90">
        <circle cx="60" cy="60" r={radius} fill="none" stroke="var(--muted)" strokeWidth="10" />
        <circle
          cx="60"
          cy="60"
          r={radius}
          fill="none"
          stroke={scoreColor(score)}
          strokeWidth="10"
          strokeLinecap="round"
          strokeDasharray={circumference}
          strokeDashoffset={circumference * (1 - score / 100)}
        />
      </svg>
      <span className="absolute inset-0 flex items-center justify-center text-3xl font-semibold">
        {score}
      </span>
    </div>
  )
}

export function HealthPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { data, isPending } = useQuery(getHealth(projectId))

  if (isPending || !data) {
    return (
      <>
        <PageHeader title="Campaign health" />
        <Skeleton className="h-40" />
        <div className="mt-4 grid gap-3 md:grid-cols-2">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-28" />
          ))}
        </div>
      </>
    )
  }

  return (
    <>
      <PageHeader title="Campaign health" subtitle="Weighted launch-readiness score across eight factors" />

      <Card>
        <CardContent className="flex flex-wrap items-center gap-6 p-6">
          <ScoreRing score={data.score} />
          <div className="min-w-0 flex-1">
            {data.readiness.ready ? (
              <p className="flex items-center gap-2 font-medium">
                <CheckCircle2 className="h-5 w-5 text-[color:var(--status-good)]" aria-hidden />
                You're in good shape to launch
              </p>
            ) : (
              <p className="flex items-center gap-2 font-medium">
                <CircleAlert className="h-5 w-5 text-[color:var(--status-warning)]" aria-hidden />
                Not ready to launch yet
              </p>
            )}
            <p className="mt-1 text-sm text-muted-foreground">
              {data.readiness.blockers.length > 0
                ? `Clear these first: ${data.readiness.blockers.map((b) => b.label.toLowerCase()).join(', ')}.`
                : 'Keep improving the weakest factors below to raise your score.'}
            </p>
          </div>
        </CardContent>
      </Card>

      <div className="mt-4 grid gap-3 md:grid-cols-2">
        {data.factors.map((factor) => (
          <Card key={factor.key}>
            <CardHeader className="pb-3">
              <div className="flex items-center justify-between">
                <CardTitle>{factor.label}</CardTitle>
                <span className="text-sm font-semibold tabular-nums">{factor.score}</span>
              </div>
              <div className="h-1.5 overflow-hidden rounded-full bg-muted" aria-hidden>
                <div
                  className="h-full rounded-full"
                  style={{ width: `${factor.score}%`, background: scoreColor(factor.score) }}
                />
              </div>
            </CardHeader>
            <CardContent>
              <CardDescription>{factor.recommendation}</CardDescription>
            </CardContent>
          </Card>
        ))}
      </div>
    </>
  )
}
