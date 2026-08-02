import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, Plus, Rocket } from 'lucide-react'
import { createProject, listProjects } from './api'
import { logout } from '@/features/auth/api'
import { ApiError } from '@/lib/api'
import { money } from '@/lib/format'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { ThemeToggle } from '@/components/layout/ThemeToggle'
import { FieldError } from '@/features/auth/AuthLayout'

export function ProjectsPage() {
  const { data, isPending } = useQuery(listProjects)
  const [creating, setCreating] = useState(false)

  const logoutMutation = useMutation({
    mutationFn: logout,
    onSuccess: () => location.assign('/login'),
  })

  return (
    <div className="mx-auto max-w-3xl px-4 py-10">
      <header className="mb-8 flex items-center justify-between">
        <div className="flex items-center gap-2.5">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary text-primary-foreground">
            <Rocket className="h-4 w-4" aria-hidden />
          </span>
          <div>
            <h1 className="text-lg font-semibold leading-tight">Kickstarter Launch OS</h1>
            <p className="text-sm text-muted-foreground">Your projects</p>
          </div>
        </div>
        <div className="flex items-center gap-1">
          <ThemeToggle />
          <Button variant="ghost" onClick={() => logoutMutation.mutate()}>
            Sign out
          </Button>
        </div>
      </header>

      {isPending ? (
        <div className="flex flex-col gap-3">
          <Skeleton className="h-24" />
          <Skeleton className="h-24" />
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          {data?.projects.map((project) => (
            <Link key={project.id} to="/projects/$projectId" params={{ projectId: String(project.id) }}>
              <Card className="transition-colors hover:border-ring/40">
                <CardContent className="flex items-center justify-between p-5">
                  <div>
                    <p className="font-medium">{project.name}</p>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                      Goal {money(project.funding_goal, project.currency)}
                      {project.launch_date &&
                        ` · launching ${new Date(project.launch_date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}`}
                    </p>
                  </div>
                  <ArrowRight className="h-4 w-4 text-muted-foreground" aria-hidden />
                </CardContent>
              </Card>
            </Link>
          ))}

          {data?.projects.length === 0 && !creating && (
            <Card>
              <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                <p className="font-medium">Start your first launch</p>
                <p className="max-w-sm text-sm text-muted-foreground">
                  A project holds your landing page, integrations, analytics and AI launch advisor.
                </p>
              </CardContent>
            </Card>
          )}

          {creating ? (
            <NewProjectCard onDone={() => setCreating(false)} />
          ) : (
            <Button variant="outline" className="self-start" onClick={() => setCreating(true)}>
              <Plus className="h-4 w-4" aria-hidden />
              New project
            </Button>
          )}
        </div>
      )}
    </div>
  )
}

function NewProjectCard({ onDone }: { onDone: () => void }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [name, setName] = useState('')
  const [goal, setGoal] = useState('25000')
  const [pledge, setPledge] = useState('45')

  const mutation = useMutation({
    mutationFn: createProject,
    onSuccess: async ({ project }) => {
      await queryClient.invalidateQueries({ queryKey: ['projects'] })
      navigate({ to: '/projects/$projectId', params: { projectId: String(project.id) } })
    },
  })

  const errors = mutation.error instanceof ApiError ? mutation.error.errors : {}

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    mutation.mutate({
      name,
      funding_goal: Math.round(Number(goal || '0') * 100),
      average_pledge: Math.round(Number(pledge || '0') * 100),
    })
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>New project</CardTitle>
        <CardDescription>We'll set up an opinionated landing page for you automatically.</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="project-name">Project name</Label>
            <Input
              id="project-name"
              required
              placeholder="Totally Football"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
            <FieldError messages={errors.name} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="project-goal">Funding goal (£)</Label>
              <Input
                id="project-goal"
                type="number"
                min="0"
                inputMode="numeric"
                value={goal}
                onChange={(e) => setGoal(e.target.value)}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="project-pledge">Average pledge (£)</Label>
              <Input
                id="project-pledge"
                type="number"
                min="0"
                inputMode="numeric"
                value={pledge}
                onChange={(e) => setPledge(e.target.value)}
              />
            </div>
          </div>
          <div className="flex gap-2">
            <Button type="submit" disabled={mutation.isPending}>
              {mutation.isPending ? 'Creating…' : 'Create project'}
            </Button>
            <Button type="button" variant="ghost" onClick={onDone}>
              Cancel
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
