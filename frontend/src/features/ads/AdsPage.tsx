import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { ChevronRight, Megaphone } from 'lucide-react'
import { getAds, getEventSetup } from './api'
import { CampaignSetupGuide } from './CampaignSetupGuide'
import { EmptyState, PageHeader } from '@/components/layout/ProjectLayout'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { money, number, percent } from '@/lib/format'
import type { Ad, AdVerdict } from '@/lib/types'

const RANGES = [7, 14, 30] as const

const VERDICT_STYLES: Record<AdVerdict, { badge: string; bar: string }> = {
  scale: { badge: 'bg-accent text-accent-foreground', bar: 'var(--status-good)' },
  keep: { badge: 'bg-muted text-muted-foreground', bar: 'var(--viz-series-1)' },
  fix: { badge: 'bg-[color:var(--status-warning)]/15 text-foreground', bar: 'var(--status-warning)' },
  drop: { badge: 'bg-destructive/10 text-destructive', bar: 'var(--status-critical)' },
  learning: { badge: 'bg-muted text-muted-foreground', bar: 'var(--viz-axis)' },
  off: { badge: 'bg-muted text-muted-foreground', bar: 'var(--viz-axis)' },
}

/**
 * Ads that are off, folded away.
 *
 * They are kept rather than dropped because their spend is part of what
 * the account has actually paid, and comparing a new creative against the
 * one it replaced is the main reason to look at a paused ad at all. But
 * they carry no verdict, so they do not belong in a list of decisions.
 */
function DisabledAds({ ads, hasLeadData }: { ads: Ad[]; hasLeadData: boolean }) {
  const [open, setOpen] = useState(false)

  if (ads.length === 0) return null

  const spend = ads.reduce((total, ad) => total + ad.spend, 0)

  return (
    <section className="mt-4">
      <button
        type="button"
        onClick={() => setOpen((wasOpen) => !wasOpen)}
        aria-expanded={open}
        className="flex w-full items-center gap-2 rounded-md border border-border px-3 py-2 text-left text-sm hover:bg-accent"
      >
        <ChevronRight
          aria-hidden
          className={cn('h-4 w-4 shrink-0 transition-transform', open && 'rotate-90')}
        />
        <span className="font-medium">
          {ads.length} turned off
        </span>
        <span className="text-muted-foreground">
          · {spend.toLocaleString('en-GB', { style: 'currency', currency: 'GBP' })} spent historically
        </span>
      </button>

      {open && (
        <div className="mt-3 flex flex-col gap-3">
          {ads.map((ad) => (
            <AdCard key={ad.ad_id} ad={ad} hasLeadData={hasLeadData} />
          ))}
        </div>
      )}
    </section>
  )
}

/**
 * How old the numbers are.
 *
 * Everything on this page is a snapshot of a Meta sync, and without
 * saying when, a figure that is merely stale is indistinguishable from
 * one that is wrong — which sends people looking for a bug instead of
 * waiting an hour.
 */
function SyncedAt({ at }: { at: string | null }) {
  if (at === null) {
    return <>What to keep, scale and switch off</>
  }

  const minutes = Math.max(0, Math.round((Date.now() - new Date(at).getTime()) / 60000))

  const ago =
    minutes < 2
      ? 'just now'
      : minutes < 60
        ? `${minutes} minutes ago`
        : minutes < 120
          ? 'an hour ago'
          : `${Math.round(minutes / 60)} hours ago`

  return (
    <>
      What to keep, scale and switch off ·{' '}
      <span className={cn(minutes > 180 && 'text-[color:var(--status-warning)]')}>
        synced {ago}
      </span>
    </>
  )
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="mt-0.5 text-sm font-medium tabular-nums">{value}</dd>
    </div>
  )
}

function AdCard({ ad, hasLeadData }: { ad: Ad; hasLeadData: boolean }) {
  const style = VERDICT_STYLES[ad.verdict]

  return (
    <Card className="overflow-hidden">
      <div className="h-1" style={{ background: style.bar }} aria-hidden />
      <CardContent className="p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="font-medium">{ad.ad_name}</p>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {ad.ad_type !== 'unknown' && `${ad.ad_type_label} · `}
              {[ad.campaign_name, ad.adset_name].filter(Boolean).join(' · ') || 'No campaign name'}
            </p>
          </div>
          <Badge className={cn('border-transparent', style.badge)}>{ad.verdict_label}</Badge>
        </div>

        <p className="mt-3 text-sm">{ad.reason}</p>
        <p className="mt-1 text-sm text-muted-foreground">
          <span className="font-medium text-foreground">Do this:</span> {ad.action}
        </p>

        <dl className="mt-4 grid grid-cols-3 gap-3 border-t border-border pt-3 sm:grid-cols-6">
          <Metric label="Spend" value={money(Math.round(ad.spend * 100))} />
          <Metric label="Clicks" value={number(ad.clicks)} />
          <Metric label="CPC" value={ad.cpc !== null ? money(Math.round(ad.cpc * 100)) : '—'} />
          {ad.follows > 0 ? (
            <>
              <Metric label="KS follows" value={number(ad.follows)} />
              <Metric
                label="Cost/follow"
                value={ad.cost_per_follow !== null ? money(Math.round(ad.cost_per_follow * 100)) : '—'}
              />
              <Metric label="Signups" value={number(ad.leads)} />
            </>
          ) : ad.ad_type === 'instant_form' && ad.form_views > 0 ? (
            <>
              <Metric label="Form opens" value={number(ad.form_views)} />
              <Metric label="Signups" value={number(ad.leads)} />
              <Metric
                label="Open→signup"
                value={ad.form_views > 0 ? percent((ad.leads / ad.form_views) * 100, 0) : '—'}
              />
            </>
          ) : ad.objective === 'traffic' ? (
            <>
              <Metric label="Page views" value={number(ad.landing_page_views)} />
              <Metric
                label="Cost/page view"
                value={ad.cost_per_page_view !== null ? money(Math.round(ad.cost_per_page_view * 100)) : '—'}
              />
              <Metric label="Signups" value={number(ad.leads)} />
            </>
          ) : (
            hasLeadData && (
              <>
                <Metric label="Signups" value={number(ad.leads)} />
                <Metric label="Cost/signup" value={ad.cpl !== null ? money(Math.round(ad.cpl * 100)) : '—'} />
                <Metric label="Click→signup" value={ad.lead_rate !== null ? percent(ad.lead_rate) : '—'} />
              </>
            )
          )}
        </dl>
      </CardContent>
    </Card>
  )
}

export function AdsPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const [days, setDays] = useState<number>(14)

  const { data, isPending } = useQuery(getAds(projectId, days))
  const { data: setup } = useQuery(getEventSetup(projectId))

  return (
    <>
      <PageHeader title="Ads" subtitle={<SyncedAt at={data?.last_synced_at ?? null} />}>
        <div className="flex gap-1" role="group" aria-label="Date range">
          {RANGES.map((range) => (
            <button
              key={range}
              onClick={() => setDays(range)}
              aria-pressed={days === range}
              className={cn(
                'rounded-md px-2.5 py-1 text-xs transition-colors',
                days === range ? 'bg-muted font-medium' : 'text-muted-foreground hover:text-foreground',
              )}
            >
              {range}d
            </button>
          ))}
        </div>
      </PageHeader>

      {setup && (
        <div className="mb-4">
          <CampaignSetupGuide setup={setup} />
        </div>
      )}

      {isPending ? (
        <div className="flex flex-col gap-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-44" />
          ))}
        </div>
      ) : !data || data.ads.length === 0 ? (
        <EmptyState
          icon={Megaphone}
          title="No ad data yet"
          body="Connect Meta Ads and run a campaign. Once spend appears, every ad gets a verdict here."
        />
      ) : (
        <>
          <Card className="mb-4">
            <CardContent className="flex flex-wrap gap-6 p-5">
              <div>
                <p className="text-xs text-muted-foreground">Spend ({data.days}d)</p>
                <p className="mt-0.5 text-lg font-semibold tabular-nums">
                  {money(Math.round(data.benchmark.total_spend * 100))}
                </p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">Signups</p>
                <p className="mt-0.5 text-lg font-semibold tabular-nums">{number(data.benchmark.total_leads)}</p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">Average cost per signup</p>
                <p className="mt-0.5 text-lg font-semibold tabular-nums">
                  {data.benchmark.account_cpl !== null
                    ? money(Math.round(data.benchmark.account_cpl * 100))
                    : '—'}
                </p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">You can afford</p>
                <p className="mt-0.5 text-lg font-semibold tabular-nums">
                  {money(Math.round(data.benchmark.affordable_cpl * 100))}
                </p>
                <p className="text-xs text-muted-foreground">per signup</p>
              </div>
            </CardContent>
          </Card>

          {data.by_type.length > 1 && (
            <div className="mb-4 grid gap-3 sm:grid-cols-3">
              {data.by_type.map((summary) => (
                <Card key={summary.type}>
                  <CardContent className="p-4">
                    <p className="text-xs text-muted-foreground">{summary.label}</p>
                    <p className="mt-1 text-lg font-semibold tabular-nums">
                      {summary.cost_per_conversion !== null
                        ? money(Math.round(summary.cost_per_conversion * 100))
                        : '—'}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      per {summary.conversion_label.replace(/s$/, '')} ·{' '}
                      {number(summary.conversions)} from {money(Math.round(summary.spend * 100))}
                    </p>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}

          {data.traffic_objective_count > 0 && (
            <div className="mb-4 rounded-lg border-l-2 border-l-[color:var(--status-warning)] bg-muted/40 p-4">
              <p className="text-sm font-medium">
                {money(Math.round(data.traffic_objective_spend * 100))} is going to ads optimised for page
                views, not signups
              </p>
              <p className="mt-1 text-sm text-muted-foreground">
                {data.traffic_objective_count === 1 ? 'One ad is' : `${data.traffic_objective_count} ads are`}{' '}
                set to buy landing page views. Meta will find the cheapest clicks it can, whether or not those
                people ever join your list. Rebuild them as a Leads or Sales campaign so Meta optimises for
                signups.
              </p>
            </div>
          )}

          <div className="flex flex-col gap-3">
            {data.ads
              .filter((ad) => ad.active)
              .map((ad) => (
                <AdCard key={ad.ad_id} ad={ad} hasLeadData={data.has_lead_data} />
              ))}
          </div>

          <DisabledAds ads={data.ads.filter((ad) => !ad.active)} hasLeadData={data.has_lead_data} />
        </>
      )}
    </>
  )
}
