export interface User {
  id: number
  name: string
  email: string
}

export interface Project {
  id: number
  name: string
  slug: string
  description: string | null
  external_landing_url: string | null
  kickstarter_url: string | null
  currency: string
  funding_goal: number
  average_pledge: number
  launch_date: string | null
  created_at: string
}

export type AdVerdict = 'scale' | 'keep' | 'fix' | 'drop' | 'learning'

export interface Ad {
  ad_id: string
  ad_name: string
  adset_name: string | null
  campaign_name: string | null
  spend: number
  impressions: number
  clicks: number
  leads: number
  view_content: number
  ctr: number | null
  cpc: number | null
  cpl: number | null
  cost_per_page_view: number | null
  landing_page_views: number
  follows: number
  cost_per_follow: number | null
  objective: 'signups' | 'traffic' | 'other'
  objective_label: string
  ad_type: 'kickstarter' | 'landing_page' | 'instant_form' | 'unknown'
  ad_type_label: string
  lead_rate: number | null
  verdict: AdVerdict
  verdict_label: string
  reason: string
  action: string
}

export interface AdTypeSummary {
  type: string
  label: string
  conversion_label: string
  ads: number
  spend: number
  conversions: number
  cost_per_conversion: number | null
}

export interface AdReport {
  days: number
  has_lead_data: boolean
  by_type: AdTypeSummary[]
  traffic_objective_count: number
  traffic_objective_spend: number
  benchmark: {
    total_spend: number
    total_leads: number
    account_cpl: number | null
    affordable_cpl: number
  }
  ads: Ad[]
}

export interface EventSetup {
  meta_connected: boolean
  pixel_detected: boolean | null
  diagnostics: Array<{ severity: 'info' | 'warning'; title: string; body: string }>
  events: Array<{
    event: string
    label: string
    purpose: string
    detected: boolean
    total: number
    last_seen: string | null
  }>
}

export interface PageCheck {
  key: string
  label: string
  passed: boolean
  weight: number
  recommendation: string
}

export interface PageAnalysis {
  id: number
  url: string
  score: number
  checks: PageCheck[]
  summary: string | null
  created_at: string
}

export interface Section {
  id: number
  type: string
  position: number
  enabled: boolean
  content: Record<string, unknown>
}

export interface LandingPage {
  id: number
  template: string
  slug: string
  published: boolean
  theme: Record<string, unknown> | null
  sections: Section[]
}

export interface CredentialField {
  label: string
  help: string
  type: 'password' | 'textarea'
  placeholder?: string
}

export interface IntegrationStatus {
  provider: string
  display_name: string
  required_credentials: string[]
  credential_fields: Record<string, CredentialField>
  docs_url: string | null
  status: 'connected' | 'needs_api_key' | 'error' | 'disconnected'
  status_message: string | null
  last_synced_at: string | null
}

export interface StatValue {
  value: number | null
  change: number | null
}

export interface DashboardCards {
  visitors: StatValue
  email_subscribers: StatValue
  vip_upgrades: StatValue
  conversion_rate: StatValue
  cac: StatValue
  revenue: StatValue
  projected_backers: StatValue
  funding_forecast: {
    value: number
    goal: number
    coverage: number
    confidence: 'low' | 'medium' | 'high'
  }
}

export interface FunnelBranch {
  key: string
  label: string
  value: number
  conversion: number | null
}

export interface FunnelStep {
  key: string
  label: string
  value: number
  conversion: number | null
  note: string | null
  /** 'window' = the rolling ad window, 'total' = the standing audience. */
  basis: 'window' | 'total'
  /** Minor units, on the impressions step only. */
  spend?: number
  branches?: FunnelBranch[]
}

export interface Funnel {
  window_days: number
  steps: FunnelStep[]
}

export interface SeriesPoint {
  date: string
  value: number
}

export interface AnalyticsMetric {
  metric: string
  label: string
  series: SeriesPoint[]
  total: number
  latest: number | null
  change: number | null
}

export interface Insight {
  id: number
  kind: 'insight' | 'recommendation'
  severity: 'info' | 'success' | 'warning' | 'critical'
  title: string
  body: string
  action: string | null
  acknowledged_at: string | null
  created_at: string
}

export interface HealthFactor {
  key: string
  label: string
  score: number
  weight: number
  recommendation: string
}

export interface CampaignHealth {
  score: number
  factors: HealthFactor[]
  readiness: { ready: boolean; score: number; blockers: HealthFactor[] }
  recommendations: Insight[]
}

export type AudienceSegment = 'standard' | 'followers' | 'vips'

export type BackerRates = Record<AudienceSegment, number>

export interface ForecastScenario {
  scenario: 'cautious' | 'expected' | 'optimistic'
  label: string
  rates: BackerRates
  expected_backers: number
  backers_by_segment: Record<AudienceSegment, number>
  expected_funding: number
  goal_coverage: number
  funds_the_goal: boolean
  is_planning: boolean
}

export interface AudienceValue {
  segment: AudienceSegment
  label: string
  count: number
  rate: number
  rate_low: number
  rate_high: number
  backers: number
  funding: number
  /** What one more of these is worth, in minor units. */
  value_each: number
}

export interface FunnelRating {
  rating: 'good' | 'warning' | 'danger'
  note: string
}

export interface ForecastRequirements {
  backers_needed: number
  backers_from_current_audience: number
  signups_needed: number
  projected_list: number
  projected_mix: Record<AudienceSegment, number>
  visitors_bought: number
  marginal_backer_rate: number
  required_conversion: { rate: number; current: number; likelihood: string } | null
  required_backer_rate: {
    rate: number
    planning: number
    ceiling: number
    likelihood: string
  } | null
}

export interface PlanDay {
  date: string
  is_future: boolean
  is_final_week: boolean
  recommended_spend: number
  cumulative_spend: number
  projected_subscribers: number | null
  actual_subscribers: number | null
}

export interface LaunchPlan {
  has_launch_date: boolean
  launched?: boolean
  launch_date?: string
  days_remaining?: number
  days: PlanDay[]
  summary?: {
    starting_list: number
    projected_at_launch: number
    target_list: number
    on_track: boolean
    shortfall: number
    daily_spend: number
    final_week_daily_spend: number
    total_spend: number
  }
}

export interface ForecastReport {
  plan: LaunchPlan
  scenarios: ForecastScenario[]
  requirements: ForecastRequirements | []
  ratings: { cpc: FunnelRating; conversion: FunnelRating }
  focus: { severity: string; title: string; body: string; action: string } | null
  audience_value: AudienceValue[]
  planning_rates: BackerRates
  recommended_budget: number
  confidence: 'low' | 'medium' | 'high'
  measured: {
    email_subscribers: number
    vip_count: number
    followers: number
    marginal_backer_rate: number
    ad_mix: BackerRates | null
    planned_ad_spend: number
    cpc: number
    visitor_to_subscriber_rate: number
    average_pledge: number
    funding_goal: number
    cpc_measured: boolean
    conversion_measured: boolean
  }
}
