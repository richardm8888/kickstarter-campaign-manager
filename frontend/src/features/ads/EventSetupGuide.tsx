import { useState } from 'react'
import { Check, ChevronDown, Copy, X } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { ActionTypeMapper } from './ActionTypeMapper'
import { cn } from '@/lib/utils'
import type { EventSetup } from '@/lib/types'

const PIXEL_SNIPPET = `<!-- Meta pixel — paste in <head> on every page -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');

fbq('init', 'YOUR_PIXEL_ID');
fbq('track', 'PageView');
</script>`

const VIEW_CONTENT_SNIPPET = `<!-- Fires when the landing page is viewed -->
<script>
  fbq('track', 'ViewContent', {
    content_name: 'Launch landing page'
  });
</script>`

const LEAD_SNIPPET = `<!-- Fire after the email form is submitted successfully -->
<script>
  document.querySelector('#signup-form')
    .addEventListener('submit', function () {
      fbq('track', 'Lead');
    });
</script>`

function CopyBlock({ code, label }: { code: string; label: string }) {
  const [copied, setCopied] = useState(false)

  const copy = async () => {
    await navigator.clipboard.writeText(code)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="relative">
      <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-3 text-xs leading-relaxed">
        <code>{code}</code>
      </pre>
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="absolute right-2 top-2"
        onClick={copy}
        aria-label={`Copy ${label}`}
      >
        <Copy className="h-3.5 w-3.5" aria-hidden />
        {copied ? 'Copied' : 'Copy'}
      </Button>
    </div>
  )
}

function EventStatus({ detected, children }: { detected: boolean; children: React.ReactNode }) {
  return (
    <span className="flex items-center gap-1.5">
      <span
        aria-hidden
        className={cn(
          'flex h-4 w-4 items-center justify-center rounded-full',
          detected ? 'bg-accent' : 'bg-muted',
        )}
      >
        {detected ? (
          <Check className="h-3 w-3 text-accent-foreground" />
        ) : (
          <X className="h-3 w-3 text-muted-foreground" />
        )}
      </span>
      {children}
    </span>
  )
}

/**
 * Walks a creator through installing the pixel and the two events that
 * make ad judgement possible — and verifies each one against live data.
 */
export function EventSetupGuide({ setup, projectId }: { setup: EventSetup; projectId: string }) {
  const allDetected = setup.events.every((e) => e.detected)
  const [open, setOpen] = useState(!allDetected)

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between space-y-0">
        <button type="button" className="text-left" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
          <CardTitle className="flex items-center gap-2">
            Conversion events
            {allDetected ? (
              <Badge variant="success">All set</Badge>
            ) : (
              <Badge variant="warning">Needs setup</Badge>
            )}
            <ChevronDown
              className={cn('h-4 w-4 text-muted-foreground transition-transform', open && 'rotate-180')}
              aria-hidden
            />
          </CardTitle>
          <CardDescription className="mt-1">
            {allDetected
              ? 'Both events are arriving — ads are being judged on signups.'
              : 'Until Lead events arrive, ads can only be ranked by click cost, not by return.'}
          </CardDescription>
        </button>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {/* Only worth asking about mappings once Meta is actually connected. */}
        <ActionTypeMapper projectId={projectId} enabled={setup.meta_connected && !allDetected} />

        {setup.diagnostics.map((finding) => (
          <div
            key={finding.title}
            className={cn(
              'rounded-lg border-l-2 bg-muted/40 p-3',
              finding.severity === 'warning'
                ? 'border-l-[color:var(--status-warning)]'
                : 'border-l-[color:var(--viz-series-1)]',
            )}
          >
            <p className="text-sm font-medium">{finding.title}</p>
            <p className="mt-0.5 text-sm text-muted-foreground">{finding.body}</p>
          </div>
        ))}

        <ul className="flex flex-col gap-2">
          {setup.events.map((event) => (
            <li key={event.event} className="flex flex-wrap items-baseline justify-between gap-2">
              <EventStatus detected={event.detected}>
                <span className="text-sm font-medium">{event.label}</span>
              </EventStatus>
              <span className="text-xs text-muted-foreground">
                {event.detected
                  ? `${event.total} in the last 14 days${event.last_seen ? ` · last ${event.last_seen}` : ''}`
                  : 'Not seen yet'}
              </span>
            </li>
          ))}
        </ul>

        {open && (
          <div className="flex flex-col gap-5 border-t border-border pt-4">
            <ol className="flex flex-col gap-5">
              <li className="flex flex-col gap-2">
                <p className="text-sm font-medium">1. Create the pixel</p>
                <p className="text-sm text-muted-foreground">
                  In Meta Events Manager choose <strong>Connect data sources → Web → Meta Pixel</strong>, name it
                  after your project, then copy its ID. Paste the snippet into the <code>&lt;head&gt;</code> of
                  every page, replacing <code>YOUR_PIXEL_ID</code>.
                </p>
                <CopyBlock code={PIXEL_SNIPPET} label="pixel snippet" />
              </li>

              <li className="flex flex-col gap-2">
                <p className="text-sm font-medium">2. Add the ViewContent event</p>
                <p className="text-sm text-muted-foreground">
                  {setup.events[0]?.purpose}
                </p>
                <CopyBlock code={VIEW_CONTENT_SNIPPET} label="ViewContent snippet" />
              </li>

              <li className="flex flex-col gap-2">
                <p className="text-sm font-medium">3. Add the Lead event</p>
                <p className="text-sm text-muted-foreground">
                  {setup.events[1]?.purpose} Fire it only after a successful submission, otherwise every page
                  view counts as a signup and your cost per lead will look far better than it is.
                </p>
                <CopyBlock code={LEAD_SNIPPET} label="Lead snippet" />
              </li>

              <li className="flex flex-col gap-2">
                <p className="text-sm font-medium">4. Verify</p>
                <p className="text-sm text-muted-foreground">
                  Use the <strong>Meta Pixel Helper</strong> browser extension on your live page, then submit a
                  test signup. Events appear in Events Manager within minutes, and here after the next hourly
                  sync.
                </p>
              </li>
            </ol>

            {setup.pixel_detected === false && (
              <p className="rounded-lg bg-muted p-3 text-sm text-muted-foreground">
                We analysed your landing page and found no tracking code on it — start with step 1.
              </p>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
