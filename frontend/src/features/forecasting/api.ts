import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { ForecastReport } from '@/lib/types'

export const getForecast = (projectId: string, plannedAdSpend: number | null) =>
  queryOptions({
    queryKey: ['projects', projectId, 'forecast', plannedAdSpend],
    queryFn: () =>
      api.get<ForecastReport>(
        `/projects/${projectId}/forecast${plannedAdSpend === null ? '' : `?planned_ad_spend=${plannedAdSpend}`}`,
      ),
  })

export function saveAdSpend(
  projectId: string,
  plannedAdSpend: number,
): Promise<{ planned_ad_spend: number; message: string }> {
  return api.put(`/projects/${projectId}/forecast/assumptions`, {
    planned_ad_spend: plannedAdSpend,
  })
}
