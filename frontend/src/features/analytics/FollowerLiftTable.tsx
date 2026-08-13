import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { number } from '@/lib/format'
import type { FollowerLift, FollowerLiftSend, SendStatus } from '@/lib/types'

/**
 * What each email did to the Kickstarter follower count.
 *
 * Deliberately not called a conversion rate. Kickstarter fires no event
 * when someone follows and Meta refuses the pixel totals, so nobody can
 * be traced from an email to a follow. This watches the follower count
 * around each send and reports the excess over an ordinary few days,
 * with ad-bought follows removed — an inference, and it says so.
 */

const STATUS_NOTE: Record<Exclude<SendStatus, 'measured'>, string> = {
  shared: 'Overlaps another send',
  no_baseline: 'Too early to compare',
  too_recent: 'Still being measured',
  unknown: 'Not enough follower data',
}

function Lift({ send }: { send: FollowerLiftSend }) {
  if (send.status === 'measured' && send.lift !== null) {
    const rounded = Math.round(send.lift)

    return (
      <span
        className={
          rounded > 0 ? 'font-medium text-[color:var(--status-good)]' : 'font-medium text-muted-foreground'
        }
      >
        {rounded > 0 ? `+${number(rounded)}` : number(rounded)}
      </span>
    )
  }

  return <span className="text-muted-foreground">—</span>
}

export function FollowerLiftTable({ lift }: { lift: FollowerLift }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Followers around each email</CardTitle>
        <CardDescription>
          Kickstarter never says who followed or why, so this is not a conversion rate. It is the
          followers gained in the {lift.lag_days} days from each send, minus the ones your ads bought
          and minus what an ordinary few days brings anyway.
        </CardDescription>
      </CardHeader>

      <CardContent>
        {lift.note && <p className="text-sm text-muted-foreground">{lift.note}</p>}

        {lift.sends.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border text-left text-xs text-muted-foreground">
                  <th className="pb-2 pr-3 font-normal">Campaign</th>
                  <th className="pb-2 pr-3 text-right font-normal">Sent to</th>
                  <th className="pb-2 pr-3 text-right font-normal">Followers gained</th>
                  <th className="pb-2 pr-3 text-right font-normal">From ads</th>
                  <th className="pb-2 pr-3 text-right font-normal">Usual</th>
                  <th className="pb-2 text-right font-normal">Down to the email</th>
                </tr>
              </thead>
              <tbody>
                {lift.sends.map((send) => (
                  <tr key={`${send.date}-${send.name}`} className="border-b border-border/50 last:border-0">
                    <td className="py-2.5 pr-3">
                      <p className="font-medium">{send.name}</p>
                      <p className="text-xs text-muted-foreground">
                        {new Date(send.date).toLocaleDateString('en-GB', {
                          day: 'numeric',
                          month: 'short',
                        })}
                        {send.status !== 'measured' && ` · ${STATUS_NOTE[send.status]}`}
                      </p>
                    </td>
                    <td className="py-2.5 pr-3 text-right tabular-nums text-muted-foreground">
                      {send.recipients > 0 ? number(send.recipients) : '—'}
                    </td>
                    <td className="py-2.5 pr-3 text-right tabular-nums">
                      {send.gain !== null ? number(Math.round(send.gain)) : '—'}
                    </td>
                    <td className="py-2.5 pr-3 text-right tabular-nums text-muted-foreground">
                      {send.ad_follows !== null ? number(Math.round(send.ad_follows)) : '—'}
                    </td>
                    <td className="py-2.5 pr-3 text-right tabular-nums text-muted-foreground">
                      {send.baseline !== null ? number(Math.round(send.baseline)) : '—'}
                    </td>
                    <td className="py-2.5 text-right tabular-nums">
                      <Lift send={send} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {lift.summary ? (
          <p className="mt-4 text-sm">
            Across {lift.summary.sends_measured}{' '}
            {lift.summary.sends_measured === 1 ? 'send' : 'sends'} that could be measured, email is
            associated with{' '}
            <span className="font-medium">
              {number(Math.round(lift.summary.total_lift))} followers
            </span>
            {lift.summary.per_1000_recipients !== null && (
              <> — about {number(Math.round(lift.summary.per_1000_recipients))} per 1,000 emails</>
            )}
            .
          </p>
        ) : (
          lift.sends.length > 0 && (
            <p className="mt-4 text-sm text-muted-foreground">
              None of these sends can be measured yet. It takes a few quiet days either side of a
              send to tell its effect from the ordinary drift.
            </p>
          )
        )}
      </CardContent>
    </Card>
  )
}
