import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { AdReport, EventSetup } from '@/lib/types'

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

