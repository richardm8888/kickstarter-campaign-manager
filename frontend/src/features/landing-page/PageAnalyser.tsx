import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Minus, Search, X } from 'lucide-react'
import { analysePage, listAnalyses } from './analysisApi'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'
import type { PageAnalysis, PageCheck, PageFinding, PageType } from '@/lib/types'

function scoreColor(score: number): string {
  if (score >= 80) return 'var(--status-good)'
  if (score >= 50) return 'var(--status-warning)'
  return 'var(--status-critical)'
}

const SEVERITY: Record<PageFinding['severity'], { label: string; color: string }> = {
  critical: { label: 'Costing you signups', color: 'var(--status-critical)' },
  warning: { label: 'Worth fixing', color: 'var(--status-warning)' },
  idea: { label: 'Idea', color: 'var(--viz-series-1)' },
}

const COPY: Record<PageType, { title: string; description: string; placeholder: string; remember: string }> = {
  landing: {
    title: 'Already have a landing page?',
    description: "Enter its URL and we'll audit it against what decides pre-launch conversion.",
    placeholder: 'https://yourdomain.com',
    remember: "Use this as my project's landing page (scores it in campaign health)",
  },
  kickstarter: {
    title: 'Your Kickstarter page',
    description:
      'Paste your pre-launch or campaign page and we’ll read it the way a browsing backer would.',
    placeholder: 'https://www.kickstarter.com/projects/you/your-project',
    remember: "Use this as my project's Kickstarter page (we'll track its followers hourly)",
  },
}

/**
 * Analyses saved before three-state results shipped have no `result`, and a
 * stored analysis is read for as long as the project exists. Resolve it once
 * here so nothing downstream indexes a lookup with undefined.
 */
function resultOf(check: PageCheck): PageCheck['result'] {
  if (check.result === 'pass' || check.result === 'fail' || check.result === 'unknown') {
    return check.result
  }

  return check.passed ? 'pass' : 'fail'
}

/** Checks first by outcome, then by what costs the most. */
function orderChecks(checks: PageCheck[]): PageCheck[] {
  const rank = { fail: 0, unknown: 1, pass: 2 } as const

  return [...checks].sort(
    (a, b) => rank[resultOf(a)] - rank[resultOf(b)] || (b.weight ?? 0) - (a.weight ?? 0),
  )
}

export function PageAnalyser({
  projectId,
  initialUrl,
  pageType = 'landing',
}: {
  projectId: string
  initialUrl: string
  pageType?: PageType
}) {
  const queryClient = useQueryClient()
  const { data } = useQuery(listAnalyses(projectId, pageType))
  const [url, setUrl] = useState(initialUrl)
  const [remember, setRemember] = useState(true)
  const copy = COPY[pageType]

  const mutation = useMutation({
    mutationFn: () => analysePage(projectId, { url, page_type: pageType, remember }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects', projectId] })
    },
  })

  const latest: PageAnalysis | undefined = mutation.data?.analysis ?? data?.analyses[0]
  const errors = mutation.error instanceof ApiError ? mutation.error.errors : {}

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    mutation.mutate()
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{copy.title}</CardTitle>
        <CardDescription>{copy.description}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <form onSubmit={onSubmit} className="flex flex-col gap-3" noValidate>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor={`analyse-url-${pageType}`}>Page URL</Label>
            <div className="flex flex-col gap-2 sm:flex-row">
              <Input
                id={`analyse-url-${pageType}`}
                type="url"
                required
                placeholder={copy.placeholder}
                value={url}
                onChange={(e) => setUrl(e.target.value)}
              />
              <Button type="submit" disabled={mutation.isPending || !url}>
                <Search className="h-4 w-4" aria-hidden />
                {mutation.isPending ? 'Analysing…' : 'Analyse'}
              </Button>
            </div>
            {errors.url?.[0] && (
              <p role="alert" className="text-xs text-destructive">
                {errors.url[0]}
              </p>
            )}
          </div>
          <label className="flex items-center gap-2 text-sm text-muted-foreground">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-input accent-[color:var(--primary)]"
              checked={remember}
              onChange={(e) => setRemember(e.target.checked)}
            />
            {copy.remember}
          </label>
        </form>

        {latest && <Result analysis={latest} />}
      </CardContent>
    </Card>
  )
}

function Result({ analysis }: { analysis: PageAnalysis }) {
  const findings = analysis.findings ?? []

  return (
    <div className="border-t border-border pt-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <div className="flex items-baseline gap-2">
          <span className="text-3xl font-semibold" style={{ color: scoreColor(analysis.score) }}>
            {analysis.score}
          </span>
          <span className="text-sm text-muted-foreground">/ 100</span>
        </div>
        <span className="text-xs text-muted-foreground">
          {new URL(analysis.url).hostname} · {new Date(analysis.created_at).toLocaleString('en-GB')}
        </span>
      </div>

      {analysis.summary && (
        <p className="mt-1.5 text-sm text-muted-foreground">{analysis.summary}</p>
      )}

      {findings.length > 0 && (
        <section className="mt-4">
          <h3 className="text-sm font-medium">Read as a visitor would</h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            Judgement, not measurement — these do not affect the score above.
          </p>
          <ul className="mt-3 flex flex-col gap-3">
            {findings.map((finding, i) => (
              <li key={i} className="border-l-2 pl-3" style={{ borderColor: SEVERITY[finding.severity].color }}>
                <p className="text-sm font-medium">
                  {finding.title}
                  <span className="sr-only"> — {SEVERITY[finding.severity].label}</span>
                </p>
                {finding.body && <p className="mt-0.5 text-sm text-muted-foreground">{finding.body}</p>}
                {finding.fix && (
                  <p className="mt-1 text-sm">
                    <span className="font-medium">Do this:</span>{' '}
                    <span className="text-muted-foreground">{finding.fix}</span>
                  </p>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="mt-4">
        <h3 className="text-sm font-medium">Checks</h3>
        <ul className="mt-2 flex flex-col gap-2">
          {orderChecks(analysis.checks).map((check) => {
            const result = resultOf(check)

            return (
              <li key={check.key} className="flex gap-2.5">
                <CheckIcon result={result} />
                <div className="min-w-0">
                  <p className="text-sm font-medium">
                    {check.label}
                    {check.detail && (
                      <span className="ml-1.5 font-normal text-muted-foreground">{check.detail}</span>
                    )}
                    <span className="sr-only">
                      : {result === 'pass' ? 'passed' : result === 'fail' ? 'needs work' : 'could not tell'}
                    </span>
                  </p>
                  {result !== 'pass' && check.recommendation && (
                    <p className="text-sm text-muted-foreground">{check.recommendation}</p>
                  )}
                </div>
              </li>
            )
          })}
        </ul>
      </section>
    </div>
  )
}

function CheckIcon({ result }: { result: PageCheck['result'] }) {
  const style = {
    pass: { className: 'bg-accent', icon: <Check className="h-3 w-3 text-accent-foreground" /> },
    fail: { className: 'bg-destructive/10', icon: <X className="h-3 w-3 text-destructive" /> },
    unknown: { className: 'bg-muted', icon: <Minus className="h-3 w-3 text-muted-foreground" /> },
  }[result]

  return (
    <span
      aria-hidden
      className={cn(
        'mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full',
        style.className,
      )}
    >
      {style.icon}
    </span>
  )
}
