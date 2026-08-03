import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { ActionTypeReport, AdReport, EventSetup } from '@/lib/types'

export const getAds = (projectId: string, days: number) =>
  queryOptions({
    queryKey: ['projects', projectId, 'ads', days],
    queryFn: () => api.get<AdReport>(`/projects/${projectId}/ads?days=${days}`),
  })

export const getEventSetup = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'ads', 'events'],
    queryFn: () => api.get<EventSetup>(`/projects/${projectId}/ads/events`),
  })

export const getActionTypes = (projectId: string, enabled: boolean) =>
  queryOptions({
    queryKey: ['projects', projectId, 'ads', 'action-types'],
    queryFn: () => api.get<ActionTypeReport>(`/projects/${projectId}/ads/action-types`),
    enabled,
    staleTime: 60_000,
  })

export function mapActionTypes(
  projectId: string,
  input: { lead_actions?: string[]; view_content_actions?: string[] },
): Promise<{ message: string }> {
  return api.put(`/projects/${projectId}/ads/action-types`, input)
}
