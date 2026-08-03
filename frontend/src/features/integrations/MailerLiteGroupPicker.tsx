import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getMailerLiteGroups, setMailerLiteGroups, type MailerLiteGroups } from './api'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { number } from '@/lib/format'

function GroupSelect({ id, value, groups, onChange }: {
  id: string
  value: string
  groups: MailerLiteGroups['groups']
  onChange: (value: string) => void
}) {
  return (
    <select
      id={id}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="h-9 w-full rounded-lg border border-input bg-transparent px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <option value="">No group</option>
      {groups.map((group) => (
        <option key={group.id} value={group.id}>
          {group.name} ({number(group.total)})
        </option>
      ))}
    </select>
  )
}

/**
 * Chooses which MailerLite groups matter to this project: where imported
 * contacts land, and which group holds VIPs so its size can be reported
 * without anyone counting by hand.
 */
export function MailerLiteGroupPicker({ projectId }: { projectId: string }) {
  const queryClient = useQueryClient()
  const { data, isPending } = useQuery(getMailerLiteGroups(projectId))
  const [groupId, setGroupId] = useState('')
  const [vipGroupId, setVipGroupId] = useState('')

  useEffect(() => {
    if (data) {
      setGroupId(data.group_id ?? '')
      setVipGroupId(data.vip_group_id ?? '')
    }
  }, [data])

  const mutation = useMutation({
    mutationFn: () =>
      setMailerLiteGroups(projectId, { group_id: groupId || null, vip_group_id: vipGroupId || null }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'mailerlite-groups'] })
    },
  })

  if (isPending) return <Skeleton className="h-16" />
  if (!data?.connected) return null

  if (data.error) {
    return (
      <p role="alert" className="mt-4 text-xs text-destructive">
        Couldn't load your groups: {data.error}
      </p>
    )
  }

  const changed = groupId !== (data.group_id ?? '') || vipGroupId !== (data.vip_group_id ?? '')

  return (
    <div className="mt-4 flex flex-col gap-4 border-t border-border pt-4">
      <div className="flex flex-col gap-1.5">
        <Label htmlFor={`${projectId}-ml-group`}>Add new contacts to group</Label>
        <p className="text-xs text-muted-foreground">
          Everyone imported from Meta lead forms joins this group, so you can target them with an automation.
        </p>
        <GroupSelect
          id={`${projectId}-ml-group`}
          value={groupId}
          groups={data.groups}
          onChange={setGroupId}
        />
      </div>

      <div className="flex flex-col gap-1.5">
        <Label htmlFor={`${projectId}-ml-vip`}>VIP group</Label>
        <p className="text-xs text-muted-foreground">
          Its size is reported as your VIP count on the dashboard and funnel.
        </p>
        <GroupSelect
          id={`${projectId}-ml-vip`}
          value={vipGroupId}
          groups={data.groups}
          onChange={setVipGroupId}
        />
      </div>

      <div className="flex items-center gap-3">
        <Button size="sm" disabled={!changed || mutation.isPending} onClick={() => mutation.mutate()}>
          {mutation.isPending ? 'Saving…' : 'Save'}
        </Button>
        {mutation.isSuccess && !changed && (
          <span role="status" className="text-xs text-muted-foreground">
            {mutation.data.message} Counts refresh on the next sync.
          </span>
        )}
      </div>

      {data.groups.length === 0 && (
        <p className="text-xs text-muted-foreground">
          No groups found in MailerLite. Create one there first, then reload this page.
        </p>
      )}
    </div>
  )
}
