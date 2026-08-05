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
 * A manual override for the hourly scrape.
 *
 * The count is read off the public page, but Kickstarter's markup is not
 * a contract and the scrape records nothing rather than guessing when it
 * changes. Followers back at roughly ten times the rate of an email
 * subscriber, so the segment is too valuable to have no way in at all
 * while a pattern is being fixed.
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
        <CardTitle>Record followers by hand</CardTitle>
        <CardDescription>
          We read this from your page every hour. Use this when the count on your dashboard
          disagrees with ours, or if we ever stop being able to read it.
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
            <>Every reading is kept, so entries and hourly readings build one growth curve.</>
          ) : (
            <>
              Add your Kickstarter page above and we will read this for you every hour.
            </>
          )}
        </p>
      </CardContent>
    </Card>
  )
}
