import { Card, CardContent } from '@/components/ui/card'
import { cn } from '@/lib/utils'

export function StatCard({ label, value, change, hint }: {
  label: string
  value: string
  change?: number | null
  hint?: string
}) {
  return (
    <Card>
      <CardContent className="p-5">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className="mt-1.5 text-2xl font-semibold tracking-tight">{value}</p>
        {change !== null && change !== undefined && (
          <p
            className={cn(
              'mt-1 text-xs font-medium',
              change >= 0 ? 'text-[color:var(--delta-good)]' : 'text-destructive',
            )}
          >
            {change >= 0 ? '↑' : '↓'} {Math.abs(change)}% vs last week
          </p>
        )}
        {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
      </CardContent>
    </Card>
  )
}
