import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Project } from '@/lib/types'

export const listProjects = queryOptions({
  queryKey: ['projects'],
  queryFn: () => api.get<{ projects: Project[] }>('/projects'),
})

export const getProject = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId],
    queryFn: () => api.get<{ project: Project }>(`/projects/${projectId}`),
  })

export interface ProjectInput {
  name: string
  description?: string | null
  external_landing_url?: string | null
  kickstarter_url?: string | null
  currency?: string
  funding_goal?: number
  average_pledge?: number
  guaranteed_backers?: number
  launch_date?: string | null
}

export function createProject(input: ProjectInput): Promise<{ project: Project }> {
  return api.post<{ project: Project }>('/projects', input)
}

export function updateProject(
  projectId: string,
  input: Partial<ProjectInput>,
): Promise<{ project: Project }> {
  return api.patch<{ project: Project }>(`/projects/${projectId}`, input)
}

export function recordFollowers(
  projectId: string,
  count: number,
): Promise<{ count: number; recorded_at: string }> {
  return api.post(`/projects/${projectId}/kickstarter-followers`, { count })
}
