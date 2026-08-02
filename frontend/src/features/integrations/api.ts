import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { IntegrationStatus } from '@/lib/types'

export const listIntegrations = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'integrations'],
    queryFn: () => api.get<{ integrations: IntegrationStatus[] }>(`/projects/${projectId}/integrations`),
  })

export function connectIntegration(
  projectId: string,
  provider: string,
  credentials: Record<string, string>,
): Promise<{ integration: IntegrationStatus }> {
  return api.post(`/projects/${projectId}/integrations/${provider}/connect`, { credentials })
}

export function disconnectIntegration(projectId: string, provider: string): Promise<{ integration: IntegrationStatus }> {
  return api.delete(`/projects/${projectId}/integrations/${provider}`)
}

export function syncIntegration(projectId: string, provider: string): Promise<{ message: string }> {
  return api.post(`/projects/${projectId}/integrations/${provider}/sync`)
}
