import { useState, type FormEvent } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Check, ExternalLink, RefreshCw } from 'lucide-react'
import {
  connectIntegration,
  disconnectIntegration,
  listIntegrations,
  syncIntegration,
} from './api'
import { PageHeader } from '@/components/layout/ProjectLayout'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Textarea } from '@/components/ui/textarea'
import { ApiError } from '@/lib/api'
import type { IntegrationStatus } from '@/lib/types'

export function IntegrationsPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { data, isPending } = useQuery(listIntegrations(projectId))

  return (
    <>
      <PageHeader
        title="Integrations"
        subtitle="Connect your stack — data syncs automatically every hour"
      />
      {isPending ? (
        <div className="flex flex-col gap-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-20" />
          ))}
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          {data?.integrations.map((integration) => (
            <IntegrationCard key={integration.provider} projectId={projectId} integration={integration} />
          ))}
        </div>
      )}
    </>
  )
}

function StatusBadge({ status }: { status: IntegrationStatus['status'] }) {
  switch (status) {
    case 'connected':
      return (
        <Badge variant="success">
          <Check className="h-3 w-3" aria-hidden /> Connected
        </Badge>
      )
    case 'error':
      return (
        <Badge variant="destructive">
          <AlertTriangle className="h-3 w-3" aria-hidden /> Error
        </Badge>
      )
    case 'needs_api_key':
      return (
        <Badge variant="warning">
          <AlertTriangle className="h-3 w-3" aria-hidden /> Needs API key
        </Badge>
      )
    default:
      return <Badge variant="outline">Not connected</Badge>
  }
}

function IntegrationCard({ projectId, integration }: {
  projectId: string
  integration: IntegrationStatus
}) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [credentials, setCredentials] = useState<Record<string, string>>({})

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'integrations'] })

  const connect = useMutation({
    mutationFn: () => connectIntegration(projectId, integration.provider, credentials),
    onSuccess: () => {
      setOpen(false)
      setCredentials({})
      invalidate()
    },
  })

  const disconnect = useMutation({
    mutationFn: () => disconnectIntegration(projectId, integration.provider),
    onSuccess: invalidate,
  })

  const sync = useMutation({
    mutationFn: () => syncIntegration(projectId, integration.provider),
  })

  const error = connect.error instanceof ApiError ? connect.error.message : null

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    connect.mutate()
  }

  return (
    <Card>
      <CardContent className="p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="font-medium">{integration.display_name}</p>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {integration.last_synced_at
                ? `Last synced ${new Date(integration.last_synced_at).toLocaleString('en-GB')}`
                : 'Never synced'}
              {integration.status_message && ` · ${integration.status_message}`}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <StatusBadge status={integration.status} />
            {integration.status === 'connected' ? (
              <>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => sync.mutate()}
                  disabled={sync.isPending}
                  aria-label={`Sync ${integration.display_name}`}
                >
                  <RefreshCw className="h-3.5 w-3.5" aria-hidden />
                  {sync.isSuccess ? 'Queued' : 'Sync'}
                </Button>
                <Button variant="outline" size="sm" onClick={() => disconnect.mutate()} disabled={disconnect.isPending}>
                  Disconnect
                </Button>
              </>
            ) : (
              <Button size="sm" onClick={() => setOpen((o) => !o)}>
                {open ? 'Close' : 'Connect'}
              </Button>
            )}
          </div>
        </div>

        {open && integration.status !== 'connected' && (
          <form onSubmit={onSubmit} className="mt-4 flex flex-col gap-4 border-t border-border pt-4" noValidate>
            {integration.docs_url && (
              <a
                href={integration.docs_url}
                target="_blank"
                rel="noreferrer noopener"
                className="flex items-center gap-1.5 text-xs font-medium text-accent-foreground hover:underline"
              >
                <ExternalLink className="h-3 w-3" aria-hidden />
                {integration.display_name} setup guide
              </a>
            )}

            {integration.required_credentials.map((field) => {
              const spec = integration.credential_fields?.[field]
              const inputId = `${integration.provider}-${field}`
              const helpId = `${inputId}-help`
              const label =
                spec?.label ?? field.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase())

              return (
                <div key={field} className="flex flex-col gap-1.5">
                  <Label htmlFor={inputId}>{label}</Label>
                  {spec?.help && (
                    <p id={helpId} className="text-xs leading-relaxed text-muted-foreground">
                      {spec.help}
                    </p>
                  )}
                  {spec?.type === 'textarea' ? (
                    <Textarea
                      id={inputId}
                      aria-describedby={spec.help ? helpId : undefined}
                      placeholder={spec.placeholder}
                      required
                      value={credentials[field] ?? ''}
                      onChange={(e) => setCredentials((c) => ({ ...c, [field]: e.target.value }))}
                    />
                  ) : (
                    <Input
                      id={inputId}
                      type="password"
                      autoComplete="off"
                      aria-describedby={spec?.help ? helpId : undefined}
                      placeholder={spec?.placeholder}
                      required
                      value={credentials[field] ?? ''}
                      onChange={(e) => setCredentials((c) => ({ ...c, [field]: e.target.value }))}
                    />
                  )}
                </div>
              )
            })}
            {error && (
              <p role="alert" className="text-xs text-destructive">
                {error}
              </p>
            )}
            <Button type="submit" size="sm" className="self-start" disabled={connect.isPending}>
              {connect.isPending ? 'Connecting…' : `Connect ${integration.display_name}`}
            </Button>
          </form>
        )}
      </CardContent>
    </Card>
  )
}
