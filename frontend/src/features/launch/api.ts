import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { LaunchTaskPlan, LaunchTask, LaunchTaskDraft } from '@/lib/types'

export const launchPlanKey = (projectId: string) => ['projects', projectId, 'launch-plan'] as const

export const getLaunchPlan = (projectId: string) =>
  queryOptions({
    queryKey: launchPlanKey(projectId),
    queryFn: () => api.get<LaunchTaskPlan>(`/projects/${projectId}/launch-tasks`),
  })

export const getDrafts = (projectId: string, taskId: number) =>
  queryOptions({
    queryKey: [...launchPlanKey(projectId), taskId, 'drafts'],
    queryFn: () => api.get<{ drafts: LaunchTaskDraft[] }>(`/projects/${projectId}/launch-tasks/${taskId}/drafts`),
  })

export const loadPlaybook = (projectId: string) =>
  api.post<{ added: number }>(`/projects/${projectId}/launch-tasks/playbook`)

export const createTask = (projectId: string, body: Partial<LaunchTask>) =>
  api.post<{ task: LaunchTask }>(`/projects/${projectId}/launch-tasks`, body)

export const updateTask = (projectId: string, taskId: number, body: Partial<LaunchTask>) =>
  api.patch<{ task: LaunchTask }>(`/projects/${projectId}/launch-tasks/${taskId}`, body)

export const deleteTask = (projectId: string, taskId: number) =>
  api.delete(`/projects/${projectId}/launch-tasks/${taskId}`)

export const saveSettings = (
  projectId: string,
  body: { team?: string[]; content_brief?: string | null; launch_date_confirmed?: boolean },
) => api.put(`/projects/${projectId}/launch-tasks/settings`, body)

export const writeDraft = (projectId: string, taskId: number, notes: string) =>
  api.post<{ draft: LaunchTaskDraft }>(`/projects/${projectId}/launch-tasks/${taskId}/drafts`, {
    notes: notes || null,
  })

export interface ImportResult {
  tasks: { line: number; title: string; category: string; phase: string; content_type: string | null; offset_days: number | null; due_on: string | null; assignee: string | null }[]
  done: { line: number; id: number; title: string }[]
  skipped: { line: number; title: string }[]
  errors: { line: number; message: string }[]
  saved: boolean
}

export const importTasks = (projectId: string, text: string, preview: boolean) =>
  api.post<ImportResult>(`/projects/${projectId}/launch-tasks/import`, { text, preview })
