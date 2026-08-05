import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { recordFollowers } from './api'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { FieldError } from '@/features/auth/AuthLayout'

/**
 * Kickstarter publishes no follower count. A pre-launch page carries only
 * a "Notify me on launch" button — the number lives in the creator's
 * dashboard, where only they can read it. Since followers back at roughly
 * ten times the rate of an email subscriber, a figure typed in weekly is
 * worth far more than an automated one that never arrives.
 */
export function FollowerEntry({ projectId, hasKickstarterUrl }: {
  projectId: string
  hasKickstarterUrl: boolean
}) {
  const queryClient = useQueryClient()
  const [count, setCount] = useState('')

  const mutation = useMutation({
    mutationFn: () => recordFollowers(projectId, Number(count)),
    onSuccess: () => {
      setCount('')
      // The follower count moves the forecast, the funnel and the
      // dashboard, so none of them should keep showing the old figure.
      queryClient.invalidateQueries({ queryKey: ['projects', projectId] })
    },
  })

  const errors = mutation.error instanceof ApiError ? mutation.error.errors : {}

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    mutation.mutate()
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Kickstarter followers</CardTitle>
        <CardDescription>
          Kickstarter shows this number to you and nobody else, so we cannot read it off your page.
          Check your dashboard when you think of it — weekly is plenty — and record it here.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <form onSubmit={onSubmit} className="flex flex-col gap-1.5" noValidate>
          <Label htmlFor="follower-count">Followers today</Label>
          <div className="flex flex-col gap-2 sm:flex-row">
            <Input
              id="follower-count"
              type="number"
              min="0"
              inputMode="numeric"
              placeholder="e.g. 480"
              value={count}
              onChange={(e) => setCount(e.target.value)}
            />
            <Button type="submit" disabled={mutation.isPending || count === ''}>
              {mutation.isPending ? 'Saving…' : 'Record'}
            </Button>
          </div>
          <FieldError messages={errors.count} />
          {mutation.isSuccess && (
            <p role="status" className="text-sm text-[color:var(--delta-good)]">
              Recorded {mutation.data.count.toLocaleString('en-GB')} followers.
            </p>
          )}
        </form>

        <p className="text-xs text-muted-foreground">
          {hasKickstarterUrl ? (
            <>
              Find it under Dashboard → your project on kickstarter.com. Each entry is kept, so the
              graph builds a growth curve as you go.
            </>
          ) : (
            <>Add your Kickstarter page above first so we can score it and link the two.</>
          )}
        </p>
      </CardContent>
    </Card>
  )
}
