import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { number } from '@/lib/format'
import type { ConversionRow } from '@/lib/types'

/**
 * Sessions against signups for one way of slicing the traffic.
 *
 * The conversion column is the point, so it is the one that gets a bar:
 * a site-wide rate hides that one referrer converts at 8% and another at
 * 0.3%, which is the difference between where the next pound should go
 * and where it currently does.
 */
export function ConversionTable({
  title,
  description,
  columnLabel,
  rows,
}: {
  title: string
  description: string
  columnLabel: string
  rows: ConversionRow[]
}) {
  const best = Math.max(...rows.map((row) => row.conversion ?? 0), 0)

  if (rows.every((row) => row.sessions === 0)) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{title}</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-sm text-muted-foreground">
            Nothing yet. This needs GA4 connected and a signup event firing on your page.
          </p>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
      <CardContent>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-xs text-muted-foreground">
                <th className="pb-2 text-left font-normal">{columnLabel}</th>
                <th className="pb-2 text-right font-normal">Sessions</th>
                <th className="pb-2 pl-3 text-right font-normal">Signups</th>
                <th className="pb-2 pl-4 text-left font-normal">Converts at</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.key} className="border-t border-border">
                  <td className="py-2 pr-3">{row.label}</td>
                  <td className="py-2 text-right tabular-nums">{number(row.sessions)}</td>
                  <td className="py-2 text-right tabular-nums">{number(row.leads)}</td>
                  <td className="py-2 pl-4">
                    {row.conversion === null ? (
                      <span className="text-xs text-muted-foreground">
                        {row.sessions === 0 ? 'no traffic' : 'too few to say'}
                      </span>
                    ) : (
                      <span className="flex items-center gap-2">
                        <span
                          aria-hidden
                          className="h-1.5 rounded-full bg-[color:var(--viz-series-1)]"
                          style={{
                            width: `${best > 0 ? Math.max(4, (row.conversion / best) * 72) : 4}px`,
                          }}
                        />
                        <span className="tabular-nums">{row.conversion}%</span>
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  )
}
