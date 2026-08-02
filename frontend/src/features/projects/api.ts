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

export function createProject(input: {
  name: string
  description?: string
  funding_goal?: number
  average_pledge?: number
  launch_date?: string
}): Promise<{ project: Project }> {
  return api.post<{ project: Project }>('/projects', input)
}
