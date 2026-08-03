import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { LandingPage, Section } from '@/lib/types'

export const getLandingPage = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'landing-page'],
    queryFn: () => api.get<{ landing_page: LandingPage }>(`/projects/${projectId}/landing-page`),
  })

export function updateLandingPage(
  projectId: string,
  input: Partial<Pick<LandingPage, 'slug' | 'published' | 'theme'>>,
): Promise<{ landing_page: LandingPage }> {
  return api.patch(`/projects/${projectId}/landing-page`, input)
}

export function updateSections(
  projectId: string,
  sections: Array<Pick<Section, 'id'> & Partial<Pick<Section, 'enabled' | 'position' | 'content'>>>,
): Promise<{ landing_page: LandingPage }> {
  return api.put(`/projects/${projectId}/landing-page/sections`, { sections })
}
