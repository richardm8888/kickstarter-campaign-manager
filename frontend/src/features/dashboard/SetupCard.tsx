import { Link } from '@tanstack/react-router'
import { ArrowRight, Check } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { cn } from '@/lib/utils'
import type { Setup } from '@/lib/types'

const ROUTES = {
  settings: '/projects/$projectId/settings',
  'landing-page': '/projects/$projectId/landing-page',
  integrations: '/projects/$projectId/integrations',
  ads: '/projects/$projectId/ads',
} as const

/**
 * The opinionated path from empty project to running campaign. Shown until
 * every step is done, then disappears — the dashboard's numbers take over
 * as the thing worth looking at.
 */
export function SetupCard({ setup, projectId }: { setup: Setup; projectId: string }) {
  if (setup.complete) {
    return null
  }

  const done = setup.steps.filter((s) => s.done).length

  return (
    <Card className="mb-6">
      <CardHeader>
        <CardTitle>Get set up</CardTitle>
        <CardDescription>
          {done} of {setup.steps.length} done. This is the order that pays off — a page before ads,
          because ads without a destination buy nothing.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <ol className="flex flex-col">
          {setup.steps.map((step, i) => (
            <li key={step.key} className={cn(i > 0 && 'border-t border-border')}>
              <Link
                to={ROUTES[step.route as keyof typeof ROUTES] ?? ROUTES.settings}
                params={{ projectId }}
                className="group flex items-start gap-3 py-3"
              >
                <span
                  aria-hidden
                  className={cn(
                    'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs',
                    step.done
                      ? 'bg-primary text-primary-foreground'
                      : 'border border-border text-muted-foreground',
                  )}
                >
                  {step.done ? <Check className="h-3 w-3" /> : i + 1}
                </span>
                <span className="min-w-0 flex-1">
                  <span
                    className={cn(
                      'block text-sm font-medium',
                      step.done && 'text-muted-foreground line-through decoration-border',
                    )}
                  >
                    {step.label}
                    <span className="sr-only">{step.done ? ' — done' : ''}</span>
                  </span>
                  {!step.done && (
                    <span className="mt-0.5 block text-xs text-muted-foreground">{step.detail}</span>
                  )}
                </span>
                {!step.done && (
                  <ArrowRight
                    aria-hidden
                    className="mt-1 h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                  />
                )}
              </Link>
            </li>
          ))}
        </ol>
      </CardContent>
    </Card>
  )
}
