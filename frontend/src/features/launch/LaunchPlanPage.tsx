import { useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Check,
  ChevronDown,
  ChevronRight,
  Copy,
  ListPlus,
  Plus,
  Settings2,
  Sparkles,
  Trash2,
  Undo2,
} from 'lucide-react'
import {
  createTask,
  deleteTask,
  getDrafts,
  getLaunchPlan,
  launchPlanKey,
  loadPlaybook,
  saveSettings,
  updateTask,
  writeDraft,
} from './api'
import { PageHeader } from '@/components/layout/ProjectLayout'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/utils'
import type { LaunchTaskPlan, LaunchTask } from '@/lib/types'

const UNASSIGNED = '__unassigned'
const EVERYONE = '__everyone'

const selectClass =
  'h-8 rounded-lg border border-border bg-card px-2 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'

function daysFromToday(date: string): number {
  const today = new Date()
  today.setHours(0, 0, 0, 0)

  return Math.round((new Date(`${date}T00:00:00`).getTime() - today.getTime()) / 86_400_000)
}

function formatDate(date: string): string {
  return new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
  })
}

/** "Today", "in 3 days", "2 days late" — the part people actually read. */
function dueLabel(task: LaunchTask): { text: string; late: boolean } | null {
  if (!task.due_date) {
    return task.offset_days === null
      ? null
      : {
          text:
            task.offset_days === 0
              ? 'Launch day'
              : `${Math.abs(task.offset_days)} days ${task.offset_days < 0 ? 'before' : 'after'} launch`,
          late: false,
        }
  }

  const days = daysFromToday(task.due_date)
  const late = task.status === 'todo' && days < 0
  const relative =
    days === 0 ? 'today' : days === 1 ? 'tomorrow' : days > 0 ? `in ${days} days` : `${-days} days late`

  return { text: `${formatDate(task.due_date)} · ${relative}`, late }
}

export function LaunchPlanPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const queryClient = useQueryClient()
  const { data, isPending } = useQuery(getLaunchPlan(projectId))
  const [person, setPerson] = useState(EVERYONE)
  const [showDone, setShowDone] = useState(false)
  const [showSettings, setShowSettings] = useState(false)
  const [settingsDismissed, setSettingsDismissed] = useState(false)
  const [adding, setAdding] = useState(false)

  const playbook = useMutation({
    mutationFn: () => loadPlaybook(projectId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: launchPlanKey(projectId) }),
  })

  const visible = useMemo(() => {
    if (!data) return []

    return data.tasks.filter((task) => {
      if (!showDone && task.status !== 'todo') return false
      if (person === EVERYONE) return true
      if (person === UNASSIGNED) return !task.assignee

      return task.assignee === person
    })
  }, [data, person, showDone])

  if (isPending || !data) {
    return (
      <>
        <PageHeader title="Launch plan" />
        <Skeleton className="h-96" />
      </>
    )
  }

  const done = data.tasks.filter((task) => task.status !== 'todo').length
  const upNext = visible
    .filter((task) => task.status === 'todo' && task.due_date)
    .sort((a, b) => (a.due_date! < b.due_date! ? -1 : 1))
    .slice(0, 5)

  return (
    <>
      <PageHeader
        title="Launch plan"
        subtitle={
          <>
            {data.launch_date ? (
              <>
                Launch {formatDate(data.launch_date)}{' '}
                {!data.launch_date_confirmed && <Badge variant="warning">provisional</Badge>}
              </>
            ) : (
              'No launch date yet: set one in Settings to see due dates'
            )}
            {data.tasks.length > 0 && ` · ${done} of ${data.tasks.length} done`}
          </>
        }
      >
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" onClick={() => setShowSettings((open) => !open)}>
            <Settings2 className="h-4 w-4" aria-hidden />
            Team &amp; AI brief
          </Button>
          {data.tasks.length > 0 && (
            <Button variant="outline" size="sm" onClick={() => setAdding((open) => !open)}>
              <Plus className="h-4 w-4" aria-hidden />
              Add task
            </Button>
          )}
        </div>
      </PageHeader>

      <div className="flex flex-col gap-4">
        {(showSettings || (data.tasks.length > 0 && data.team.length === 0 && !settingsDismissed)) && (
          <PlanSettings
            projectId={projectId}
            plan={data}
            onDone={() => {
              setShowSettings(false)
              setSettingsDismissed(true)
            }}
          />
        )}

        {adding && <AddTask projectId={projectId} plan={data} onDone={() => setAdding(false)} />}

        {data.tasks.length === 0 ? (
          <Card>
            <CardContent className="flex flex-col items-center gap-3 py-10 text-center">
              <ListPlus className="h-8 w-8 text-muted-foreground" aria-hidden />
              <div>
                <p className="text-sm font-medium">Start from the launch playbook</p>
                <p className="mt-1 max-w-md text-sm text-muted-foreground">
                  Around 40 steps from foundations to the last 48 hours of the campaign, each timed against
                  launch day, so the plan moves when the date does. Edit, assign or delete anything.
                </p>
              </div>
              <Button disabled={playbook.isPending} onClick={() => playbook.mutate()}>
                Load the playbook
              </Button>
            </CardContent>
          </Card>
        ) : (
          <>
            <div className="flex flex-wrap items-center gap-2">
              {[EVERYONE, ...data.team, UNASSIGNED].map((name) => (
                <Button
                  key={name}
                  size="sm"
                  variant={person === name ? 'default' : 'secondary'}
                  onClick={() => setPerson(name)}
                >
                  {name === EVERYONE ? 'Everyone' : name === UNASSIGNED ? 'Unassigned' : name}
                </Button>
              ))}
              <label className="ml-auto flex items-center gap-2 text-xs text-muted-foreground">
                <Switch checked={showDone} onCheckedChange={setShowDone} aria-label="Show finished tasks" />
                Show finished
              </label>
            </div>

            {upNext.length > 0 && (
              <Card>
                <CardHeader>
                  <CardTitle>Up next</CardTitle>
                  <CardDescription>The next things due{person !== EVERYONE ? ' for this filter' : ''}</CardDescription>
                </CardHeader>
                <CardContent>
                  <ul className="flex flex-col gap-1.5">
                    {upNext.map((task) => {
                      const due = dueLabel(task)

                      return (
                        <li key={task.id} className="flex flex-wrap items-baseline justify-between gap-x-4 text-sm">
                          <span>
                            {task.title}
                            {task.assignee && <span className="text-muted-foreground"> · {task.assignee}</span>}
                          </span>
                          {due && (
                            <span className={cn('text-xs', due.late ? 'text-destructive' : 'text-muted-foreground')}>
                              {due.text}
                            </span>
                          )}
                        </li>
                      )
                    })}
                  </ul>
                </CardContent>
              </Card>
            )}

            {Object.entries(data.phases).map(([phase, label]) => {
              const tasks = visible.filter((task) => task.phase === phase)
              if (tasks.length === 0) return null

              return (
                <section key={phase} className="flex flex-col gap-2">
                  <h2 className="mt-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    {label}
                  </h2>
                  {tasks.map((task) => (
                    <TaskRow key={task.id} projectId={projectId} plan={data} task={task} />
                  ))}
                </section>
              )
            })}

            {visible.length === 0 && (
              <p className="py-6 text-center text-sm text-muted-foreground">Nothing left here. Nice work.</p>
            )}

            <div>
              <Button variant="ghost" size="sm" disabled={playbook.isPending} onClick={() => playbook.mutate()}>
                Add any missing playbook steps
              </Button>
              {playbook.data && (
                <span className="text-xs text-muted-foreground">
                  {playbook.data.added === 0 ? 'Nothing missing.' : `Added ${playbook.data.added}.`}
                </span>
              )}
            </div>
          </>
        )}
      </div>
    </>
  )
}

function TaskRow({ projectId, plan, task }: { projectId: string; plan: LaunchTaskPlan; task: LaunchTask }) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const due = dueLabel(task)
  const finished = task.status !== 'todo'

  const update = useMutation({
    mutationFn: (body: Partial<LaunchTask>) => updateTask(projectId, task.id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: launchPlanKey(projectId) }),
  })

  const remove = useMutation({
    mutationFn: () => deleteTask(projectId, task.id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: launchPlanKey(projectId) }),
  })

  return (
    <Card className={cn(finished && 'opacity-60')}>
      <div className="flex items-start gap-3 p-4">
        <button
          type="button"
          aria-label={task.status === 'done' ? 'Mark as not done' : 'Mark as done'}
          disabled={update.isPending}
          onClick={() => update.mutate({ status: task.status === 'done' ? 'todo' : 'done' })}
          className={cn(
            'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md border border-border',
            task.status === 'done' && 'border-primary bg-primary text-primary-foreground',
          )}
        >
          {task.status === 'done' && <Check className="h-3.5 w-3.5" aria-hidden />}
        </button>

        <div className="min-w-0 flex-1">
          <button
            type="button"
            onClick={() => setOpen((value) => !value)}
            className="flex w-full items-start gap-1 text-left"
            aria-expanded={open}
          >
            {open ? (
              <ChevronDown className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
            ) : (
              <ChevronRight className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
            )}
            <span className={cn('text-sm font-medium', task.status === 'done' && 'line-through')}>{task.title}</span>
          </button>

          <div className="mt-1.5 flex flex-wrap items-center gap-2 pl-5">
            <Badge>{plan.categories[task.category] ?? task.category}</Badge>
            {task.content_type && (
              <Badge variant="outline">
                <Sparkles className="h-3 w-3" aria-hidden />
                {plan.content_types[task.content_type]}
              </Badge>
            )}
            {task.status === 'skipped' && <Badge>Skipped</Badge>}
            {due && (
              <span className={cn('text-xs', due.late ? 'font-medium text-destructive' : 'text-muted-foreground')}>
                {due.text}
              </span>
            )}
            <select
              aria-label="Who is doing this"
              className={cn(selectClass, 'ml-auto')}
              value={task.assignee ?? ''}
              onChange={(event) => update.mutate({ assignee: event.target.value || null })}
            >
              <option value="">Unassigned</option>
              {plan.team.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
              {task.assignee && !plan.team.includes(task.assignee) && (
                <option value={task.assignee}>{task.assignee}</option>
              )}
            </select>
          </div>

          {open && (
            <div className="mt-3 flex flex-col gap-4 pl-5">
              {task.details && <p className="whitespace-pre-line text-sm text-muted-foreground">{task.details}</p>}

              {task.content_type && <Writer projectId={projectId} plan={plan} task={task} />}

              <div className="flex flex-wrap gap-2">
                {task.status === 'todo' ? (
                  <Button size="sm" variant="ghost" onClick={() => update.mutate({ status: 'skipped' })}>
                    Skip
                  </Button>
                ) : (
                  <Button size="sm" variant="ghost" onClick={() => update.mutate({ status: 'todo' })}>
                    <Undo2 className="h-4 w-4" aria-hidden />
                    Back to to-do
                  </Button>
                )}
                <Button
                  size="sm"
                  variant="ghost"
                  className="text-destructive"
                  disabled={remove.isPending}
                  onClick={() => {
                    if (confirm(`Delete "${task.title}"?`)) remove.mutate()
                  }}
                >
                  <Trash2 className="h-4 w-4" aria-hidden />
                  Delete
                </Button>
              </div>
            </div>
          )}
        </div>
      </div>
    </Card>
  )
}

/**
 * The "write it for me" panel. The draft is a starting point to edit, so
 * it is shown as plain text with a copy button rather than anything that
 * looks finished.
 */
function Writer({ projectId, plan, task }: { projectId: string; plan: LaunchTaskPlan; task: LaunchTask }) {
  const queryClient = useQueryClient()
  const [notes, setNotes] = useState('')
  const [copied, setCopied] = useState(false)
  const [showHistory, setShowHistory] = useState(false)
  const history = useQuery({ ...getDrafts(projectId, task.id), enabled: showHistory })

  const write = useMutation({
    mutationFn: () => writeDraft(projectId, task.id, notes),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: launchPlanKey(projectId) })
    },
  })

  const draft = write.data?.draft ?? task.latest_draft

  const copy = async (text: string) => {
    await navigator.clipboard.writeText(text)
    setCopied(true)
    setTimeout(() => setCopied(false), 1500)
  }

  if (!plan.ai_available) {
    return (
      <p className="rounded-lg bg-muted p-3 text-xs text-muted-foreground">
        AI writing is switched off on this server. Add <code>ANTHROPIC_API_KEY</code> to the backend environment
        to turn on "Write it for me".
      </p>
    )
  }

  return (
    <div className="flex flex-col gap-2 rounded-lg border border-border p-3">
      <Label htmlFor={`notes-${task.id}`} className="text-xs">
        Anything to include? (optional)
      </Label>
      <Textarea
        id={`notes-${task.id}`}
        rows={2}
        value={notes}
        placeholder="For example: mention the trailer, keep it under 100 words, the reviewer is Adam at Punchboard"
        onChange={(event) => setNotes(event.target.value)}
      />
      <div className="flex flex-wrap items-center gap-2">
        <Button size="sm" disabled={write.isPending} onClick={() => write.mutate()}>
          <Sparkles className="h-4 w-4" aria-hidden />
          {write.isPending ? 'Writing…' : draft ? 'Write another version' : 'Write it for me'}
        </Button>
        {write.error && (
          <span className="text-xs text-destructive">
            {write.error instanceof ApiError ? write.error.message : 'Something went wrong.'}
          </span>
        )}
      </div>

      {draft && (
        <div className="flex flex-col gap-2">
          <pre className="max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-muted p-3 font-sans text-sm">
            {draft.content}
          </pre>
          <div className="flex flex-wrap items-center gap-2">
            <Button size="sm" variant="outline" onClick={() => copy(draft.content)}>
              <Copy className="h-4 w-4" aria-hidden />
              {copied ? 'Copied' : 'Copy'}
            </Button>
            <span className="text-xs text-muted-foreground">
              A first draft: check anything in [brackets] and every fact before posting.
            </span>
            {task.drafts_count > 1 && (
              <Button size="sm" variant="ghost" onClick={() => setShowHistory((value) => !value)}>
                {showHistory ? 'Hide' : 'Show'} earlier versions ({task.drafts_count - 1})
              </Button>
            )}
          </div>
          {showHistory &&
            history.data?.drafts
              .filter((earlier) => earlier.id !== draft.id)
              .map((earlier) => (
                <details key={earlier.id} className="rounded-lg border border-border p-2 text-sm">
                  <summary className="cursor-pointer text-xs text-muted-foreground">
                    {new Date(earlier.created_at).toLocaleString('en-GB')}
                    {earlier.notes && ` · "${earlier.notes}"`}
                  </summary>
                  <pre className="mt-2 whitespace-pre-wrap font-sans">{earlier.content}</pre>
                </details>
              ))}
        </div>
      )}
    </div>
  )
}

function PlanSettings({ projectId, plan, onDone }: { projectId: string; plan: LaunchTaskPlan; onDone: () => void }) {
  const queryClient = useQueryClient()
  const [team, setTeam] = useState(plan.team.join(', '))
  const [brief, setBrief] = useState(plan.content_brief ?? '')
  const [confirmed, setConfirmed] = useState(plan.launch_date_confirmed)

  const save = useMutation({
    mutationFn: () =>
      saveSettings(projectId, {
        team: team.split(',').map((name) => name.trim()).filter(Boolean),
        content_brief: brief || null,
        launch_date_confirmed: confirmed,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: launchPlanKey(projectId) })
      onDone()
    },
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle>Team &amp; AI brief</CardTitle>
        <CardDescription>Who is splitting the work, and what the AI should know when it writes for you.</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="team">Team (comma separated)</Label>
          <Input id="team" value={team} placeholder="Richard, Sean" onChange={(event) => setTeam(event.target.value)} />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="brief">Brief for the AI writer</Label>
          <Textarea
            id="brief"
            rows={8}
            value={brief}
            placeholder="What the game is, the key facts (players, age, play time, what's in the box), your voice, quotes you're allowed to use, and anything to avoid."
            onChange={(event) => setBrief(event.target.value)}
          />
        </div>
        <label className="flex items-start gap-3 text-sm">
          <Switch checked={confirmed} onCheckedChange={setConfirmed} aria-label="Launch date confirmed" />
          <span>
            Launch date is confirmed
            <span className="block text-xs text-muted-foreground">
              Until this is on, drafts say "coming soon" and never mention the date.
            </span>
          </span>
        </label>
        <div className="flex gap-2">
          <Button disabled={save.isPending} onClick={() => save.mutate()}>
            Save
          </Button>
          <Button variant="ghost" onClick={onDone}>
            Cancel
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

function AddTask({ projectId, plan, onDone }: { projectId: string; plan: LaunchTaskPlan; onDone: () => void }) {
  const queryClient = useQueryClient()
  const [title, setTitle] = useState('')
  const [phase, setPhase] = useState(Object.keys(plan.phases)[1] ?? 'audience')
  const [category, setCategory] = useState(Object.keys(plan.categories)[0] ?? 'kickstarter')
  const [dueOn, setDueOn] = useState('')
  const [contentType, setContentType] = useState('')
  const [assignee, setAssignee] = useState('')
  const [details, setDetails] = useState('')

  const add = useMutation({
    mutationFn: () =>
      createTask(projectId, {
        title,
        phase,
        category,
        details: details || null,
        due_on: dueOn || null,
        content_type: contentType || null,
        assignee: assignee || null,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: launchPlanKey(projectId) })
      onDone()
    },
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle>Add a task</CardTitle>
      </CardHeader>
      <CardContent>
        <form
          className="flex flex-col gap-3"
          onSubmit={(event) => {
            event.preventDefault()
            if (title.trim()) add.mutate()
          }}
        >
          <Input value={title} placeholder="What needs doing" onChange={(event) => setTitle(event.target.value)} />
          <Textarea
            rows={2}
            value={details}
            placeholder="Details (optional): also what the AI should write about"
            onChange={(event) => setDetails(event.target.value)}
          />
          <div className="flex flex-wrap gap-2">
            <select aria-label="Phase" className={selectClass} value={phase} onChange={(e) => setPhase(e.target.value)}>
              {Object.entries(plan.phases).map(([key, label]) => (
                <option key={key} value={key}>
                  {label}
                </option>
              ))}
            </select>
            <select
              aria-label="Category"
              className={selectClass}
              value={category}
              onChange={(e) => setCategory(e.target.value)}
            >
              {Object.entries(plan.categories).map(([key, label]) => (
                <option key={key} value={key}>
                  {label}
                </option>
              ))}
            </select>
            <select
              aria-label="Content to write"
              className={selectClass}
              value={contentType}
              onChange={(e) => setContentType(e.target.value)}
            >
              <option value="">Nothing to write</option>
              {Object.entries(plan.content_types).map(([key, label]) => (
                <option key={key} value={key}>
                  {label}
                </option>
              ))}
            </select>
            <select
              aria-label="Assignee"
              className={selectClass}
              value={assignee}
              onChange={(e) => setAssignee(e.target.value)}
            >
              <option value="">Unassigned</option>
              {plan.team.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
            <Input
              type="date"
              aria-label="Due date"
              className="h-8 w-auto text-xs"
              value={dueOn}
              onChange={(e) => setDueOn(e.target.value)}
            />
          </div>
          {add.error && (
            <p className="text-xs text-destructive">
              {add.error instanceof ApiError ? add.error.message : 'Something went wrong.'}
            </p>
          )}
          <div className="flex gap-2">
            <Button type="submit" disabled={add.isPending || !title.trim()}>
              Add
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
