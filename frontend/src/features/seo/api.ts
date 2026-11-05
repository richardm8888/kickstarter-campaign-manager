import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'

export interface SeoRow {
  key: string
  clicks: number
  impressions: number
  ctr: number
  position: number
  previous_clicks?: number | null
  previous_impressions?: number | null
  previous_position?: number | null
  change?: number
  expected_ctr?: number
  missed_clicks?: number
}

export interface SeoTotals {
  clicks: number
  impressions: number
  ctr: number
  position: number | null
}

export interface SeoAnalysis {
  window: { days: number; start: string; end: string; previous_start: string; previous_end: string }
  summary: { current: SeoTotals; previous: SeoTotals }
  queries: SeoRow[]
  pages: SeoRow[]
  striking_distance: { query: string; page: string; impressions: number; clicks: number; ctr: number; position: number }[]
  low_ctr: SeoRow[]
  cannibalization: { query: string; impressions: number; pages: { page: string; impressions: number; position: number }[] }[]
  movers: { risers: SeoRow[]; fallers: SeoRow[]; new: SeoRow[]; lost: SeoRow[] }
  generated_at: string
}

export interface SeoRecommendation {
  title: string
  why: string
  action: string
  priority: 'high' | 'medium' | 'low'
  category: string
  content_type: string | null
}

export const seoKey = (projectId: string, days: number) => ['projects', projectId, 'seo', days] as const

export const getSeo = (projectId: string, days: number, refresh = false) =>
  queryOptions({
    queryKey: seoKey(projectId, days),
    queryFn: () =>
      api.get<{ connected: boolean; ai_available: boolean; analysis: SeoAnalysis | null }>(
        `/projects/${projectId}/seo?days=${days}${refresh ? '&refresh=1' : ''}`,
      ),
    retry: false,
    staleTime: 5 * 60_000,
  })

export const getRecommendations = (projectId: string, days: number) =>
  api.post<{ recommendations: SeoRecommendation[] }>(`/projects/${projectId}/seo/recommendations`, { days })
