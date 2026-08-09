import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { DailyBrief, DailyTask, DailyTaskStatus } from '@/lib/types'

export const getDailyBrief = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'daily'],
    queryFn: () => api.get<DailyBrief>(`/projects/${projectId}/daily`),
  })

export const getDailyHistory = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'daily', 'history'],
    queryFn: () => api.get<{ tasks: DailyTask[] }>(`/projects/${projectId}/daily/history`),
  })

export function setTaskStatus(
  projectId: string,
  taskId: number,
  status: DailyTaskStatus,
): Promise<{ task: DailyTask }> {
  return api.patch(`/projects/${projectId}/daily/${taskId}`, { status })
}
