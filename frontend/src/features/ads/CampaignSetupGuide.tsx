import { useState } from 'react'
import { Check, ChevronDown, Copy, X } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
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

function Campaign({ number, title, children }: {
  number: number
  title: string
  children: React.ReactNode
}) {
  return (
    <div className="rounded-lg border border-border p-4">
      <p className="text-sm font-semibold">
        Ad {number} — {title}
      </p>
      <div className="mt-2 flex flex-col gap-2 text-sm text-muted-foreground">{children}</div>
    </div>
  )
}

/**
 * The prescribed pre-launch ad setup: three ads, two events. The platform
 * reads results on that basis rather than adapting to arbitrary
 * configurations, so the guide is the contract.
 */
export function CampaignSetupGuide({ setup }: { setup: EventSetup }) {
  const allDetected = setup.events.every((e) => e.detected)
  const [open, setOpen] = useState(!allDetected)

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between space-y-0">
        <button type="button" className="text-left" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
          <CardTitle className="flex items-center gap-2">
            Campaign setup
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
              ? 'Both events are arriving — ads are being judged on signups and follows.'
              : 'Three ads, two events. Follow this and every number on this page becomes reliable.'}
          </CardDescription>
        </button>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
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
            <div className="flex flex-col gap-3">
              <p className="text-sm font-medium">Run these three ads</p>
              <p className="text-sm text-muted-foreground">
                Each buys something different, and each is judged on its own terms below. Start with the
                instant form — it is the cheapest — then add the others once it is working.
              </p>

              <Campaign number={1} title="Instant form">
                <p>
                  Objective <strong>Leads</strong>, conversion location <strong>Instant forms</strong>. Ask for
                  the email only — every extra question costs you signups.
                </p>
                <p>
                  The cheapest way to collect addresses, because nobody has to leave Facebook. We pull the
                  submissions out automatically and add them to your list.
                </p>
              </Campaign>

              <Campaign number={2} title="Your landing page">
                <p>
                  Objective <strong>Leads</strong> or <strong>Sales</strong>, destination your own page, with
                  the pixel and a <strong>Lead</strong> event on the signup form.
                </p>
                <p>
                  More friction than a form, but you control the pitch — and you can offer the £1 VIP upgrade,
                  which a Facebook form cannot do.
                </p>
              </Campaign>

              <Campaign number={3} title="Your Kickstarter page">
                <p>
                  Objective <strong>Traffic</strong>, destination your Kickstarter pre-launch page. Add your
                  pixel ID in Kickstarter's project settings so the page reports back.
                </p>
                <p>
                  A "Notify me on launch" tap fires <strong>Lead</strong> there. Kickstarter alerts followers
                  the moment you go live, so they convert better than a cold email — but Kickstarter owns the
                  relationship, so we count follows separately from addresses you own.
                </p>
              </Campaign>

              <div className="rounded-lg border-l-2 border-l-[color:var(--status-warning)] bg-muted/40 p-3">
                <p className="text-sm font-medium">Don't optimise for landing page views</p>
                <p className="mt-0.5 text-sm text-muted-foreground">
                  Meta buys whatever you ask for. Ask for page views and it finds the cheapest clickers it can,
                  whether or not they ever sign up. Ads set that way are flagged below.
                </p>
              </div>
            </div>

            <ol className="flex flex-col gap-5 border-t border-border pt-4">
              <li className="flex flex-col gap-2">
                <p className="text-sm font-medium">1. Install the pixel</p>
                <p className="text-sm text-muted-foreground">
                  Events Manager → <strong>Connect data sources → Web → Meta Pixel</strong>. Paste this into
                  the <code>&lt;head&gt;</code> of every page, replacing <code>YOUR_PIXEL_ID</code>. Add the
                  same ID to your Kickstarter pre-launch page settings.
                </p>
                <CopyBlock code={PIXEL_SNIPPET} label="pixel snippet" />
              </li>

              <li className="flex flex-col gap-2">
                <p className="text-sm font-medium">2. Fire Lead on your own signup form</p>
                <p className="text-sm text-muted-foreground">
                  Only if you also collect emails on your own page. Fire it after a successful submission —
                  fire it on page load and every visitor counts as a signup, making your cost per signup look
                  far better than it is.
                </p>
                <CopyBlock code={LEAD_SNIPPET} label="Lead snippet" />
              </li>

              <li className="flex flex-col gap-2">
                <p className="text-sm font-medium">3. Verify</p>
                <p className="text-sm text-muted-foreground">
                  Use the <strong>Meta Pixel Helper</strong> extension on the live page, then submit a test
                  signup. Events reach Events Manager within minutes and appear above after the next hourly
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
