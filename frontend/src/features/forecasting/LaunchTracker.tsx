import {
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { money, number, shortDate } from '@/lib/format'
import type { LaunchPlan } from '@/lib/types'

/**
 * Projected list growth to launch day, with what actually happened plotted
 * against it. Two series, so the legend is always shown.
 */
export function LaunchTracker({ plan }: { plan: LaunchPlan }) {
  if (!plan.has_launch_date) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Launch plan</CardTitle>
          <CardDescription>
            Set a launch date in Settings and we'll pace your budget day by day up to it.
          </CardDescription>
        </CardHeader>
      </Card>
    )
  }

  if (plan.launched || !plan.summary) {
    return null
  }

  const { summary } = plan

  const data = plan.days.map((day) => ({
    date: day.date,
    projected: day.projected_subscribers,
    actual: day.actual_subscribers,
  }))

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          An audience of {number(summary.projected_at_launch)} by{' '}
          {new Date(plan.launch_date!).toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'long',
          })}
        </CardTitle>
        <CardDescription>
          {summary.on_track ? (
            <>
              That clears the {number(summary.target_list)} you need — {plan.days_remaining} days to go.
            </>
          ) : (
            <>
              {number(summary.shortfall)} short of the {number(summary.target_list)} you need, with{' '}
              {plan.days_remaining} days to go.
            </>
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="h-64" role="img" aria-label="Projected and actual audience size over time">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
              <CartesianGrid stroke="var(--viz-grid)" vertical={false} />
              <XAxis
                dataKey="date"
                tickFormatter={shortDate}
                tick={{ fill: 'var(--viz-axis)', fontSize: 11 }}
                axisLine={{ stroke: 'var(--viz-grid)' }}
                tickLine={false}
                minTickGap={40}
              />
              <YAxis
                width={44}
                tick={{ fill: 'var(--viz-axis)', fontSize: 11 }}
                axisLine={false}
                tickLine={false}
              />
              <Tooltip
                cursor={{ stroke: 'var(--viz-axis)', strokeWidth: 1 }}
                contentStyle={{
                  background: 'var(--card)',
                  border: '1px solid var(--border)',
                  borderRadius: 8,
                  fontSize: 12,
                  color: 'var(--foreground)',
                }}
                labelFormatter={(value) => shortDate(String(value))}
              />
              <Legend
                verticalAlign="top"
                align="left"
                height={28}
                wrapperStyle={{ fontSize: 12, color: 'var(--muted-foreground)' }}
              />
              <ReferenceLine
                y={summary.target_list}
                stroke="var(--viz-axis)"
                strokeDasharray="4 4"
                label={{
                  value: `target ${number(summary.target_list)}`,
                  position: 'insideTopRight',
                  fill: 'var(--viz-axis)',
                  fontSize: 11,
                }}
              />
              <Line
                type="monotone"
                dataKey="projected"
                name="Projected"
                stroke="var(--viz-series-1)"
                strokeWidth={2}
                strokeDasharray="5 4"
                dot={false}
                connectNulls
              />
              <Line
                type="monotone"
                dataKey="actual"
                name="Actual"
                stroke="var(--viz-series-2)"
                strokeWidth={2}
                dot={false}
                connectNulls
              />
            </LineChart>
          </ResponsiveContainer>
        </div>

        <div className="grid gap-3 border-t border-border pt-4 sm:grid-cols-3">
          <div>
            <p className="text-xs text-muted-foreground">Spend per day</p>
            <p className="mt-0.5 text-lg font-semibold tabular-nums">{money(summary.daily_spend)}</p>
            <p className="text-xs text-muted-foreground">until the final week</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Final 7 days</p>
            <p className="mt-0.5 text-lg font-semibold tabular-nums">
              {money(summary.final_week_daily_spend)}
            </p>
            <p className="text-xs text-muted-foreground">per day</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Audience now</p>
            <p className="mt-0.5 text-lg font-semibold tabular-nums">{number(summary.starting_list)}</p>
            <p className="text-xs text-muted-foreground">subscribers & followers</p>
          </div>
        </div>

        <p className="text-xs text-muted-foreground">
          Spend rises into launch week: interest peaks then, and a subscriber who joins days before you go
          live is far likelier to back than one who joined months ago.
        </p>
      </CardContent>
    </Card>
  )
}
