import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { PageAnalysis } from '@/lib/types'

export const listAnalyses = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'page-analyses'],
    queryFn: () => api.get<{ analyses: PageAnalysis[] }>(`/projects/${projectId}/page-analyses`),
  })

export function analysePage(
  projectId: string,
  input: { url: string; remember: boolean },
): Promise<{ analysis: PageAnalysis }> {
  return api.post(`/projects/${projectId}/page-analyses`, input)
}
