import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { importTasks, launchPlanKey, type ImportResult } from './api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Textarea } from '@/components/ui/textarea'
import { ApiError } from '@/lib/api'
import type { LaunchTaskPlan } from '@/lib/types'

function dueText(task: ImportResult['tasks'][number]): string {
  if (task.due_on) return task.due_on
  if (task.offset_days === null) return 'no date'
  if (task.offset_days === 0) return 'launch day'

  return `${Math.abs(task.offset_days)} days ${task.offset_days < 0 ? 'before' : 'after'} launch`
}

/**
 * Paste a list, check exactly what it will do, then import it. A list
 * with any mistake imports nothing, and every mistake names its line.
 */
export function ImportTasks({ projectId, plan, onDone }: { projectId: string; plan: LaunchTaskPlan; onDone: () => void }) {
  const queryClient = useQueryClient()
  const [text, setText] = useState('')
  const [result, setResult] = useState<ImportResult | null>(null)

  const run = useMutation({
    mutationFn: (preview: boolean) => importTasks(projectId, text, preview),
    onSuccess: (data) => {
      setResult(data)
      if (data.saved) queryClient.invalidateQueries({ queryKey: launchPlanKey(projectId) })
    },
  })

  const saved = result?.saved === true
  const hasErrors = (result?.errors.length ?? 0) > 0
  const checked = result !== null && !hasErrors

  return (
    <Card>
      <CardHeader>
        <CardTitle>Import tasks</CardTitle>
        <CardDescription>
          One task per line: <code>Title | category | content to write | due | who | details</code>. Only the title is
          needed. Due is days from launch (<code>-84</code>, <code>+7</code>, <code>launch</code>) or a date (
          <code>2026-11-27</code>). <code>done: welcome_email</code> or <code>done: exact task title</code> ticks off a
          step, and lines starting <code>#</code> are ignored.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <Textarea
          rows={10}
          value={text}
          className="font-mono text-xs"
          placeholder={'Pitch Punchboard for a preview | Outreach | Outreach email | -84 | Richard | Adam reviewed Superclub\ndone: welcome_email'}
          onChange={(event) => {
            setText(event.target.value)
            setResult(null)
          }}
        />
        <p className="text-xs text-muted-foreground">
          Categories: {Object.values(plan.categories).join(', ')}. Content: {Object.values(plan.content_types).join(', ')}.
        </p>

        <div className="flex flex-wrap gap-2">
          {!checked ? (
            <Button disabled={!text.trim() || run.isPending} onClick={() => run.mutate(true)}>
              {run.isPending ? 'Checking…' : 'Check the list'}
            </Button>
          ) : (
            !saved && (
              <Button
                disabled={run.isPending || (result.tasks.length === 0 && result.done.length === 0)}
                onClick={() => run.mutate(false)}
              >
                {run.isPending ? 'Importing…' : 'Import'}
              </Button>
            )
          )}
          <Button variant="ghost" onClick={onDone}>
            {saved ? 'Close' : 'Cancel'}
          </Button>
        </div>

        {run.error && (
          <p role="alert" className="text-xs text-destructive">
            {run.error instanceof ApiError ? run.error.message : 'Something went wrong.'}
          </p>
        )}

        {result && hasErrors && (
          <div className="flex flex-col gap-1 rounded-lg border border-destructive/40 p-3 text-xs">
            <p className="font-medium text-destructive">Fix these and check again. Nothing has been imported.</p>
            {result.errors.map((error) => (
              <p key={`${error.line}-${error.message}`}>
                <span className="font-medium">Line {error.line}:</span> {error.message}
              </p>
            ))}
          </div>
        )}

        {result && !hasErrors && (
          <div className="flex flex-col gap-3 rounded-lg border border-border p-3 text-sm">
            <p className="font-medium">
              {saved
                ? `Imported ${result.tasks.length} new ${result.tasks.length === 1 ? 'task' : 'tasks'} and ticked off ${result.done.length}.`
                : result.tasks.length === 0 && result.done.length === 0
                  ? 'Nothing new to import.'
                  : `Ready to add ${result.tasks.length} ${result.tasks.length === 1 ? 'task' : 'tasks'} and tick off ${result.done.length}. Press Import to save.`}
            </p>
            {result.tasks.length > 0 && (
              <ul className="flex flex-col gap-1">
                {result.tasks.map((task) => (
                  <li key={task.line} className="text-xs">
                    <span className="font-medium">{task.title}</span>{' '}
                    <span className="text-muted-foreground">
                      · {plan.categories[task.category]} · {plan.phases[task.phase]} · {dueText(task)}
                      {task.content_type && ` · writes: ${plan.content_types[task.content_type]}`}
                      {task.assignee && ` · ${task.assignee}`}
                    </span>
                  </li>
                ))}
              </ul>
            )}
            {result.done.length > 0 && (
              <ul className="flex flex-col gap-1">
                {result.done.map((row) => (
                  <li key={row.line} className="text-xs">
                    <span className="text-muted-foreground">Tick off:</span> {row.title}
                  </li>
                ))}
              </ul>
            )}
            {result.skipped.length > 0 && (
              <p className="text-xs text-muted-foreground">
                Already in the plan, so skipped: {result.skipped.map((row) => row.title).join(', ')}
              </p>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
