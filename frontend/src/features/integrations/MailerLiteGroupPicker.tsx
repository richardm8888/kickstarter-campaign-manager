import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getMailerLiteGroups, setMailerLiteGroup } from './api'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { number } from '@/lib/format'

/**
 * Chooses which MailerLite group imported contacts join. Groups are what
 * automations and segments hang off, so landing everyone in the right one
 * is usually the difference between a usable list and a pile of emails.
 */
export function MailerLiteGroupPicker({ projectId }: { projectId: string }) {
  const queryClient = useQueryClient()
  const { data, isPending } = useQuery(getMailerLiteGroups(projectId))
  const [selected, setSelected] = useState<string>('')

  useEffect(() => {
    if (data) setSelected(data.group_id ?? '')
  }, [data])

  const mutation = useMutation({
    mutationFn: (groupId: string) => setMailerLiteGroup(projectId, groupId || null),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'mailerlite-groups'] })
    },
  })

  if (isPending) return <Skeleton className="h-16" />
  if (!data?.connected) return null

  if (data.error) {
    return (
      <p role="alert" className="text-xs text-destructive">
        Couldn't load your groups: {data.error}
      </p>
    )
  }

  const groupId = `${projectId}-ml-group`
  const changed = selected !== (data.group_id ?? '')

  return (
    <div className="mt-4 flex flex-col gap-2 border-t border-border pt-4">
      <Label htmlFor={groupId}>Add new contacts to group</Label>
      <p className="text-xs text-muted-foreground">
        Everyone imported from Meta lead forms joins this group, so you can target them with an automation.
      </p>
      <div className="flex flex-col gap-2 sm:flex-row">
        <select
          id={groupId}
          value={selected}
          onChange={(e) => setSelected(e.target.value)}
          className="h-9 flex-1 rounded-lg border border-input bg-transparent px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <option value="">No group</option>
          {data.groups.map((group) => (
            <option key={group.id} value={group.id}>
              {group.name} ({number(group.total)})
            </option>
          ))}
        </select>
        <Button
          size="sm"
          disabled={!changed || mutation.isPending}
          onClick={() => mutation.mutate(selected)}
        >
          {mutation.isPending ? 'Saving…' : 'Save'}
        </Button>
      </div>
      {data.groups.length === 0 && (
        <p className="text-xs text-muted-foreground">
          No groups found in MailerLite. Create one there first, then reload this page.
        </p>
      )}
      {mutation.isSuccess && !changed && (
        <p role="status" className="text-xs text-muted-foreground">
          {mutation.data.message}
        </p>
      )}
    </div>
  )
}
