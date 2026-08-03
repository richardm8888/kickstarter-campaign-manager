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

export interface MailerLiteGroups {
  connected: boolean
  groups: Array<{ id: string; name: string; total: number }>
  group_id: string | null
  error?: string
}

export const getMailerLiteGroups = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'mailerlite-groups'],
    queryFn: () =>
      api.get<MailerLiteGroups>(`/projects/${projectId}/integrations/mailerlite/groups`),
  })

export function setMailerLiteGroup(
  projectId: string,
  groupId: string | null,
): Promise<{ group_id: string | null; message: string }> {
  return api.put(`/projects/${projectId}/integrations/mailerlite/group`, { group_id: groupId })
}
