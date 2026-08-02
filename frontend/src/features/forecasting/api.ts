import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Forecast, ForecastInput } from '@/lib/types'

export const getForecast = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'forecast'],
    queryFn: () => api.get<{ forecast: Forecast; input: ForecastInput }>(`/projects/${projectId}/forecast`),
  })

export function previewForecast(
  projectId: string,
  overrides: Partial<ForecastInput>,
): Promise<{ forecast: Forecast }> {
  return api.post(`/projects/${projectId}/forecast/preview`, overrides)
}
