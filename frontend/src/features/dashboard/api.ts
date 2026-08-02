import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { DashboardCards, FunnelStep } from '@/lib/types'

export const getDashboard = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'dashboard'],
    queryFn: () =>
      api.get<{ cards: DashboardCards; funnel: FunnelStep[]; currency: string }>(
        `/projects/${projectId}/dashboard`,
      ),
  })
