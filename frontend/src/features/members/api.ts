import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { ProjectInvitation, ProjectMembers } from '@/lib/types'

const PENDING_INVITE_KEY = 'klos.pendingInvite'

/**
 * An invite being followed through sign-up or log-in.
 *
 * Kept for the browser session so that hopping between the log-in and
 * create-account pages does not lose it. Storage can be unavailable
 * (private mode), in which case the person simply opens the link again.
 */
export function rememberInvite(token: string | null): void {
  try {
    if (token) sessionStorage.setItem(PENDING_INVITE_KEY, token)
    else sessionStorage.removeItem(PENDING_INVITE_KEY)
  } catch {
    // Nothing to do: the invite page still works when opened directly.
  }
}

export function pendingInvite(): string | null {
  try {
    return sessionStorage.getItem(PENDING_INVITE_KEY)
  } catch {
    return null
  }
}

export function inviteLink(token: string): string {
  return `${location.origin}/invite/${token}`
}

export const getMembers = (projectId: string) =>
  queryOptions({
    queryKey: ['projects', projectId, 'members'],
    queryFn: () => api.get<ProjectMembers>(`/projects/${projectId}/members`),
  })

export const getInvitation = (token: string) =>
  queryOptions({
    queryKey: ['invitations', token],
    queryFn: () =>
      api.get<{ project: { id: number; name: string }; invited_by: string; email: string }>(`/invitations/${token}`),
    retry: false,
  })

export const invite = (projectId: string, email: string) =>
  api.post<{ invitation: ProjectInvitation }>(`/projects/${projectId}/members`, { email })

export const revokeInvitation = (projectId: string, invitationId: number) =>
  api.delete(`/projects/${projectId}/invitations/${invitationId}`)

export const removeMember = (projectId: string, userId: number) =>
  api.delete<{ message: string }>(`/projects/${projectId}/members/${userId}`)

export const acceptInvitation = (token: string) =>
  api.post<{ project: { id: number; name: string } }>(`/invitations/${token}/accept`)
