import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { number } from '@/lib/format'
import type { EmailLinkRow } from '@/lib/types'

/** The host and path, without the UTM tail that makes every row look alike. */
function readable(url: string): string {
  try {
    const parsed = new URL(url)

    return `${parsed.hostname.replace(/^www\./, '')}${parsed.pathname.replace(/\/$/, '')}`
  } catch {
    return url
  }
}

/**
 * Clicks on each link inside the emails, counted by MailerLite.
 *
 * Deliberately separate from the session tables above. MailerLite counts
 * a click when the link is followed; GA4 counts a session when the page
 * loads and the tag fires. The two disagreeing is not an error — the gap
 * is people who clicked and never arrived, which is worth seeing rather
 * than reconciling away.
 */
export function EmailLinksTable({ rows }: { rows: EmailLinkRow[] }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Links clicked inside your emails</CardTitle>
        <CardDescription>
          Counted by MailerLite when the link is followed, so this can exceed the sessions above —
          the difference is people who clicked and never landed.
        </CardDescription>
      </CardHeader>

      <CardContent>
        {rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            No per-link data has come back from MailerLite yet. It arrives on the next sync after a
            campaign goes out.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-xs text-muted-foreground">
                  <th className="pb-2 pr-3 font-normal">Link</th>
                  <th className="pb-2 text-right font-normal">Clicks</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={`${row.campaign}-${row.url}`} className="border-b border-border/50 last:border-0">
                    <td className="py-2.5 pr-3">
                      <p className="font-medium break-all">{readable(row.url)}</p>
                      <p className="text-xs text-muted-foreground">{row.campaign}</p>
                    </td>
                    <td className="py-2.5 text-right tabular-nums">{number(row.clicks)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
