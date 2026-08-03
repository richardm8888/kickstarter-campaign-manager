import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getActionTypes, mapActionTypes } from './api'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { number } from '@/lib/format'

/**
 * Shown when Meta reports conversions we are not counting — usually a
 * custom conversion. Lets the creator say which one means "signup"
 * instead of leaving the ad verdicts blind.
 */
export function ActionTypeMapper({ projectId, enabled }: { projectId: string; enabled: boolean }) {
  const queryClient = useQueryClient()
  const { data, isPending } = useQuery(getActionTypes(projectId, enabled))
  const [selected, setSelected] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: (actionType: string) => mapActionTypes(projectId, { lead_actions: [actionType] }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'ads'] })
    },
  })

  if (!enabled) return null
  if (isPending) return <Skeleton className="h-24" />
  if (!data || data.error || data.action_types.length === 0) return null

  const unmapped = data.action_types.filter(
    (type) =>
      !data.mapping.lead.includes(type.action_type) &&
      !data.mapping.view_content.includes(type.action_type) &&
      type.total > 0,
  )

  if (unmapped.length === 0) return null

  return (
    <div className="rounded-lg border border-border bg-muted/40 p-4">
      <p className="text-sm font-medium">Meta is reporting conversions we're not counting</p>
      <p className="mt-1 text-sm text-muted-foreground">
        These are usually custom conversions. If one of them is your email signup, select it and we'll judge
        your ads on it.
      </p>

      <ul className="mt-3 flex flex-col gap-1.5">
        {unmapped.map((type) => (
          <li key={type.action_type}>
            <label className="flex items-center gap-2.5 text-sm">
              <input
                type="radio"
                name="lead-action"
                className="h-4 w-4 accent-[color:var(--primary)]"
                checked={selected === type.action_type}
                onChange={() => setSelected(type.action_type)}
              />
              <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{type.action_type}</code>
              <span className="text-xs text-muted-foreground">{number(type.total)} in 14 days</span>
            </label>
          </li>
        ))}
      </ul>

      <div className="mt-3 flex items-center gap-3">
        <Button
          size="sm"
          disabled={!selected || mutation.isPending}
          onClick={() => selected && mutation.mutate(selected)}
        >
          {mutation.isPending ? 'Saving…' : 'This is my signup event'}
        </Button>
        {mutation.isSuccess && (
          <span role="status" className="text-sm text-muted-foreground">
            {mutation.data.message}
          </span>
        )}
      </div>
    </div>
  )
}
