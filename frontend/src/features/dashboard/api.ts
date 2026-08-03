import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { DashboardCards, Funnel, Setup } from '@/lib/types'

export const getDashboard = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'dashboard'],
    queryFn: () =>
      api.get<{ setup: Setup; cards: DashboardCards; funnel: Funnel; currency: string }>(
        `/projects/${projectId}/dashboard`,
      ),
  })
