import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { AnalyticsMetric } from '@/lib/types'

export type AnalyticsCategory = 'traffic' | 'ads' | 'email' | 'revenue'

export const getAnalytics = (projectId: string, category: AnalyticsCategory, days: number) =>
  queryOptions({
    queryKey: ['projects', projectId, 'analytics', category, days],
    queryFn: () =>
      api.get<{ category: string; metrics: AnalyticsMetric[] }>(
        `/projects/${projectId}/analytics?category=${category}&days=${days}`,
      ),
  })
