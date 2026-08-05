import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { PageAnalysis, PageType } from '@/lib/types'

export const listAnalyses = (projectId: string, pageType: PageType) =>
  queryOptions({
    queryKey: ['projects', projectId, 'page-analyses', pageType],
    queryFn: () =>
      api.get<{ analyses: PageAnalysis[] }>(
        `/projects/${projectId}/page-analyses?page_type=${pageType}`,
      ),
  })

export function analysePage(
  projectId: string,
  input: { url: string; page_type: PageType; remember: boolean },
): Promise<{ analysis: PageAnalysis }> {
  return api.post(`/projects/${projectId}/page-analyses`, input)
}
