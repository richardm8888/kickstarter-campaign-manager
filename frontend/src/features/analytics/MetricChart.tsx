import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { SeriesPoint } from '@/lib/types'
import { shortDate } from '@/lib/format'

/**
 * Single-series area chart. One hue (viz series blue), recessive grid,
 * crosshair tooltip; the card title names the series so no legend is needed.
 */
export function MetricChart({ series, label }: { series: SeriesPoint[]; label: string }) {
  if (series.length === 0) {
    return (
      <div className="flex h-40 items-center justify-center text-sm text-muted-foreground">
        No data yet
      </div>
    )
  }

  return (
    <div className="h-40" role="img" aria-label={`${label} over time`}>
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={series} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
          <defs>
            <linearGradient id="metric-fill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="var(--viz-series-1)" stopOpacity={0.25} />
              <stop offset="100%" stopColor="var(--viz-series-1)" stopOpacity={0.02} />
            </linearGradient>
          </defs>
          <CartesianGrid stroke="var(--viz-grid)" strokeDasharray="0" vertical={false} />
          <XAxis
            dataKey="date"
            tickFormatter={shortDate}
            tick={{ fill: 'var(--viz-axis)', fontSize: 11 }}
            axisLine={{ stroke: 'var(--viz-grid)' }}
            tickLine={false}
            minTickGap={32}
          />
          <YAxis
            width={40}
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
            formatter={(value) => [String(value), label]}
          />
          <Area
            type="monotone"
            dataKey="value"
            stroke="var(--viz-series-1)"
            strokeWidth={2}
            fill="url(#metric-fill)"
            dot={false}
            activeDot={{ r: 4 }}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}
