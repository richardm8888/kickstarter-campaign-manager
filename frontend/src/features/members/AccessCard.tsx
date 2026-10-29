import { useState, type FormEvent } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, UserMinus, X } from 'lucide-react'
import { getMembers, invite, inviteLink, removeMember, revokeInvitation } from './api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { ApiError } from '@/lib/api'

/**
 * Who can work on the project. The owner invites by link; everyone else
 * sees who is on the team and can leave.
 */
export function AccessCard({ projectId, currentUserId }: { projectId: string; currentUserId?: number }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { data } = useQuery(getMembers(projectId))
  const [email, setEmail] = useState('')
  const [copied, setCopied] = useState<number | null>(null)

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'members'] })

  const add = useMutation({
    mutationFn: () => invite(projectId, email),
    onSuccess: () => {
      setEmail('')
      refresh()
    },
  })

  const revoke = useMutation({
    mutationFn: (id: number) => revokeInvitation(projectId, id),
    onSuccess: refresh,
  })

  const remove = useMutation({
    mutationFn: (userId: number) => removeMember(projectId, userId),
    onSuccess: (_, userId) => {
      if (userId === currentUserId) {
        queryClient.invalidateQueries({ queryKey: ['projects'] })
        navigate({ to: '/projects' })
      } else {
        refresh()
      }
    },
  })

  if (!data) return null

  const copy = async (id: number, token: string) => {
    await navigator.clipboard.writeText(inviteLink(token))
    setCopied(id)
    setTimeout(() => setCopied(null), 1500)
  }

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (email.trim()) add.mutate()
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Who has access</CardTitle>
        <CardDescription>
          Everyone here can see and change the project. Only the owner can invite people, remove them or delete the
          project.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <ul className="flex flex-col gap-2">
          <li className="flex items-center justify-between gap-3 text-sm">
            <span>
              {data.owner.name} <span className="text-muted-foreground">· {data.owner.email}</span>
            </span>
            <Badge>Owner</Badge>
          </li>
          {data.members.map((member) => (
            <li key={member.id} className="flex items-center justify-between gap-3 text-sm">
              <span>
                {member.name} <span className="text-muted-foreground">· {member.email}</span>
              </span>
              {(data.is_owner || member.id === currentUserId) && (
                <Button
                  size="sm"
                  variant="ghost"
                  disabled={remove.isPending}
                  onClick={() => {
                    const leaving = member.id === currentUserId
                    if (confirm(leaving ? 'Leave this project?' : `Remove ${member.name}'s access?`)) {
                      remove.mutate(member.id)
                    }
                  }}
                >
                  <UserMinus className="h-4 w-4" aria-hidden />
                  {member.id === currentUserId ? 'Leave' : 'Remove'}
                </Button>
              )}
            </li>
          ))}
        </ul>

        {data.is_owner && (
          <>
            {data.invitations.length > 0 && (
              <div className="flex flex-col gap-2">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  Waiting to join
                </h3>
                {data.invitations.map((invitation) => (
                  <div key={invitation.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span>
                      {invitation.email}{' '}
                      <span className="text-xs text-muted-foreground">
                        · link expires {new Date(invitation.expires_at).toLocaleDateString('en-GB')}
                      </span>
                    </span>
                    <div className="flex gap-1">
                      <Button size="sm" variant="outline" onClick={() => copy(invitation.id, invitation.token)}>
                        <Copy className="h-4 w-4" aria-hidden />
                        {copied === invitation.id ? 'Copied' : 'Copy invite link'}
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label={`Cancel invite for ${invitation.email}`}
                        onClick={() => revoke.mutate(invitation.id)}
                      >
                        <X className="h-4 w-4" aria-hidden />
                      </Button>
                    </div>
                  </div>
                ))}
                <p className="text-xs text-muted-foreground">
                  Send the link by text, WhatsApp or email. It works once and expires after 14 days.
                </p>
              </div>
            )}

            <form onSubmit={onSubmit} className="flex flex-col gap-1.5">
              <Label htmlFor="invite-email">Invite someone</Label>
              <div className="flex gap-2">
                <Input
                  id="invite-email"
                  type="email"
                  placeholder="their@email.com"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                />
                <Button type="submit" disabled={add.isPending || !email.trim()}>
                  Create invite link
                </Button>
              </div>
              {add.error && (
                <p role="alert" className="text-xs text-destructive">
                  {add.error instanceof ApiError ? add.error.message : 'Something went wrong.'}
                </p>
              )}
            </form>
          </>
        )}
      </CardContent>
    </Card>
  )
}
