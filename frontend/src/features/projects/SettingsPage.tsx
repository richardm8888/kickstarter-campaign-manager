import { useEffect, useState, type FormEvent } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getProject, updateProject } from './api'
import { PageHeader } from '@/components/layout/ProjectLayout'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Textarea } from '@/components/ui/textarea'
import { FieldError } from '@/features/auth/AuthLayout'

interface Form {
  name: string
  description: string
  currency: string
  funding_goal: string
  average_pledge: string
  launch_date: string
  external_landing_url: string
}

export function SettingsPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const queryClient = useQueryClient()
  const { data, isPending } = useQuery(getProject(projectId))

  const [form, setForm] = useState<Form | null>(null)

  useEffect(() => {
    if (data && form === null) {
      const p = data.project
      setForm({
        name: p.name,
        description: p.description ?? '',
        currency: p.currency,
        funding_goal: String(p.funding_goal / 100),
        average_pledge: String(p.average_pledge / 100),
        launch_date: p.launch_date ? p.launch_date.slice(0, 10) : '',
        external_landing_url: p.external_landing_url ?? '',
      })
    }
  }, [data, form])

  const mutation = useMutation({
    mutationFn: (input: Form) =>
      updateProject(projectId, {
        name: input.name,
        description: input.description || null,
        currency: input.currency,
        funding_goal: Math.round(Number(input.funding_goal || '0') * 100),
        average_pledge: Math.round(Number(input.average_pledge || '0') * 100),
        launch_date: input.launch_date || null,
        external_landing_url: input.external_landing_url || null,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects'] })
    },
  })

  if (isPending || !form) {
    return (
      <>
        <PageHeader title="Settings" />
        <Skeleton className="h-96" />
      </>
    )
  }

  const set = (key: keyof Form) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
    setForm((f) => (f ? { ...f, [key]: e.target.value } : f))

  const errors = mutation.error instanceof ApiError ? mutation.error.errors : {}

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    mutation.mutate(form)
  }

  return (
    <>
      <PageHeader title="Settings" subtitle="Your goals drive every forecast and recommendation" />

      <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
        <Card>
          <CardHeader>
            <CardTitle>Project</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="s-name">Name</Label>
              <Input id="s-name" required value={form.name} onChange={set('name')} />
              <FieldError messages={errors.name} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="s-description">Description</Label>
              <Textarea
                id="s-description"
                className="font-sans text-sm"
                value={form.description}
                onChange={set('description')}
              />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Funding goals</CardTitle>
            <CardDescription>
              These feed the funding forecast, campaign health score and the "subscribers needed" advice.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="s-goal">Funding goal</Label>
              <Input id="s-goal" type="number" min="0" value={form.funding_goal} onChange={set('funding_goal')} />
              <FieldError messages={errors.funding_goal} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="s-pledge">Average pledge</Label>
              <Input id="s-pledge" type="number" min="0" value={form.average_pledge} onChange={set('average_pledge')} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="s-currency">Currency</Label>
              <Input
                id="s-currency"
                maxLength={3}
                value={form.currency}
                onChange={(e) => setForm((f) => (f ? { ...f, currency: e.target.value.toUpperCase() } : f))}
              />
              <FieldError messages={errors.currency} />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Launch</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="s-launch">Planned launch date</Label>
              <Input id="s-launch" type="date" value={form.launch_date} onChange={set('launch_date')} />
              <p className="text-xs text-muted-foreground">
                Shown as a countdown on your dashboard. Leave empty if you haven't decided.
              </p>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="s-external">Your own landing page (optional)</Label>
              <Input
                id="s-external"
                type="url"
                placeholder="https://yourdomain.com"
                value={form.external_landing_url}
                onChange={set('external_landing_url')}
              />
              <p className="text-xs text-muted-foreground">
                Set this if you use your own page instead of the built-in one — we'll score it instead.
              </p>
              <FieldError messages={errors.external_landing_url} />
            </div>
          </CardContent>
        </Card>

        <div className="flex items-center gap-3">
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : 'Save changes'}
          </Button>
          {mutation.isSuccess && (
            <span role="status" className="text-sm text-[color:var(--delta-good)]">
              Saved
            </span>
          )}
        </div>
      </form>
    </>
  )
}
