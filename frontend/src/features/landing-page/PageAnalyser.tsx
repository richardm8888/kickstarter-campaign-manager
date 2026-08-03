import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Search, X } from 'lucide-react'
import { analysePage, listAnalyses } from './analysisApi'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'
import type { PageAnalysis } from '@/lib/types'

function scoreColor(score: number): string {
  if (score >= 80) return 'var(--status-good)'
  if (score >= 50) return 'var(--status-warning)'
  return 'var(--status-critical)'
}

export function PageAnalyser({ projectId, initialUrl }: { projectId: string; initialUrl: string }) {
  const queryClient = useQueryClient()
  const { data } = useQuery(listAnalyses(projectId))
  const [url, setUrl] = useState(initialUrl)
  const [remember, setRemember] = useState(true)

  const mutation = useMutation({
    mutationFn: () => analysePage(projectId, { url, remember }),
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
        <CardTitle>Already have a landing page?</CardTitle>
        <CardDescription>
          Enter its URL and we'll audit it against what decides pre-launch conversion.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <form onSubmit={onSubmit} className="flex flex-col gap-3" noValidate>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="analyse-url">Page URL</Label>
            <div className="flex flex-col gap-2 sm:flex-row">
              <Input
                id="analyse-url"
                type="url"
                required
                placeholder="https://yourdomain.com"
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
            Use this as my project's landing page (scores it in campaign health)
          </label>
        </form>

        {latest && (
          <div className="border-t border-border pt-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <div className="flex items-baseline gap-2">
                <span className="text-3xl font-semibold" style={{ color: scoreColor(latest.score) }}>
                  {latest.score}
                </span>
                <span className="text-sm text-muted-foreground">/ 100</span>
              </div>
              <span className="text-xs text-muted-foreground">
                {new URL(latest.url).hostname} · {new Date(latest.created_at).toLocaleString('en-GB')}
              </span>
            </div>

            {latest.summary && <p className="mt-1.5 text-sm text-muted-foreground">{latest.summary}</p>}

            <ul className="mt-4 flex flex-col gap-2">
              {[...latest.checks]
                .sort((a, b) => Number(a.passed) - Number(b.passed) || b.weight - a.weight)
                .map((check) => (
                  <li key={check.key} className="flex gap-2.5">
                    <span
                      aria-hidden
                      className={cn(
                        'mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full',
                        check.passed ? 'bg-accent' : 'bg-destructive/10',
                      )}
                    >
                      {check.passed ? (
                        <Check className="h-3 w-3 text-accent-foreground" />
                      ) : (
                        <X className="h-3 w-3 text-destructive" />
                      )}
                    </span>
                    <div className="min-w-0">
                      <p className="text-sm font-medium">
                        {check.label}
                        <span className="sr-only">: {check.passed ? 'passed' : 'needs work'}</span>
                      </p>
                      {!check.passed && (
                        <p className="text-sm text-muted-foreground">{check.recommendation}</p>
                      )}
                    </div>
                  </li>
                ))}
            </ul>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
