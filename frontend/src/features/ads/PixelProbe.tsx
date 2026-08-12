import { useMutation } from '@tanstack/react-query'
import { Check, Search, X } from 'lucide-react'
import { runPixelProbe } from './api'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

/**
 * Asks Meta which pixel event data it will return, from the browser.
 *
 * The answer decides whether follows that came from an email can ever be
 * counted — they fire the pixel but were never an ad click, so Meta
 * attributes them to nothing. There is a matching artisan command, but
 * the person who needs the answer is usually holding a phone.
 *
 * Meta's error text is shown verbatim. "This edge no longer exists" and
 * "your token lacks a permission" have opposite fixes and read
 * identically once summarised.
 */
export function PixelProbe({ projectId }: { projectId: string }) {
  const mutation = useMutation({
    mutationFn: () => runPixelProbe(projectId),
  })

  const error = mutation.error instanceof ApiError ? mutation.error.message : null

  return (
    <Card>
      <CardHeader>
        <CardTitle>What will Meta tell us about your pixel?</CardTitle>
        <CardDescription>
          Ad reporting only shows conversions Meta attributed to an ad. Someone who followed your
          Kickstarter page after clicking an email fires the pixel too, but never clicked an ad — so
          those follows are invisible. This asks whether the totals are readable.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div>
          <Button onClick={() => mutation.mutate()} disabled={mutation.isPending}>
            <Search className="h-4 w-4" aria-hidden />
            {mutation.isPending ? 'Asking Meta…' : 'Check'}
          </Button>
        </div>

        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}

        {mutation.data?.pixels.length === 0 && (
          <p className="text-sm text-muted-foreground">
            No pixels on this ad account. Follows are being recorded, so the pixel may belong to a
            different account than the one running the ads.
          </p>
        )}

        {mutation.data?.pixels.map((pixel) => (
          <div key={pixel.id}>
            <p className="text-sm font-medium">{pixel.name}</p>
            <p className="text-xs text-muted-foreground">
              {pixel.id} ·{' '}
              {pixel.last_fired_at
                ? `last fired ${new Date(pixel.last_fired_at).toLocaleString('en-GB')}`
                : 'never fired'}
            </p>

            <ul className="mt-2 flex flex-col gap-2">
              {pixel.checks.map((check) => (
                <li key={check.label} className="flex gap-2.5">
                  <span
                    aria-hidden
                    className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full ${
                      check.ok ? 'bg-accent' : 'bg-destructive/10'
                    }`}
                  >
                    {check.ok ? (
                      <Check className="h-3 w-3 text-accent-foreground" />
                    ) : (
                      <X className="h-3 w-3 text-destructive" />
                    )}
                  </span>
                  <div className="min-w-0">
                    <p className="text-sm font-medium">
                      {check.label}
                      <span className="ml-1.5 font-normal text-muted-foreground">
                        {check.ok ? `${check.rows} rows` : 'not available'}
                      </span>
                    </p>
                    {(check.detail ?? check.sample) && (
                      <p className="mt-0.5 break-words font-mono text-xs text-muted-foreground">
                        {check.detail ?? check.sample}
                      </p>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          </div>
        ))}

        {mutation.data && (
          <p className="text-xs text-muted-foreground">
            Send this to Claude. A green "Totals by event" means unbought follows can be counted;
            all red means that door is shut and follower growth around each send is the way in.
          </p>
        )}
      </CardContent>
    </Card>
  )
}
