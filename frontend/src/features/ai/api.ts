import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { CampaignHealth, Insight } from '@/lib/types'

export const listInsights = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'insights'],
    queryFn: () => api.get<{ insights: Insight[] }>(`/projects/${projectId}/insights`),
  })

export const getHealth = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'health'],
    queryFn: () => api.get<CampaignHealth>(`/projects/${projectId}/health`),
  })

export function generateInsights(projectId: string): Promise<{ created: Insight[] }> {
  return api.post(`/projects/${projectId}/insights/generate`)
}

export function acknowledgeInsight(projectId: string, insightId: number): Promise<{ insight: Insight }> {
  return api.patch(`/projects/${projectId}/insights/${insightId}/acknowledge`)
}
