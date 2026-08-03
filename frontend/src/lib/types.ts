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
  currency: string
  funding_goal: number
  average_pledge: number
  launch_date: string | null
  created_at: string
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

export interface FunnelStep {
  key: string
  label: string
  value: number
  conversion: number | null
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

export interface Forecast {
  projected_visitors: number
  projected_subscribers: number
  projected_vips: number
  expected_backers: number
  expected_funding: number
  funding_goal: number
  goal_coverage: number
  confidence: 'low' | 'medium' | 'high'
  assumptions: Record<string, number>
}

export interface ForecastInput {
  email_subscribers: number
  vip_count: number
  planned_ad_spend: number
  cpc: number
  visitor_to_subscriber_rate: number
  subscriber_to_backer_rate: number
  vip_to_backer_rate: number
  average_pledge: number
  funding_goal: number
}
