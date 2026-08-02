import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ExternalLink } from 'lucide-react'
import { getLandingPage, updateLandingPage, updateSections } from './api'
import { PageHeader } from '@/components/layout/ProjectLayout'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import type { Section } from '@/lib/types'

const SECTION_LABELS: Record<string, string> = {
  hero: 'Hero',
  cta: 'Call to action',
  feature_grid: 'Feature grid',
  video: 'Video',
  testimonials: 'Testimonials',
  faq: 'FAQ',
  email_capture: 'Email capture',
  vip_upgrade: '£1 VIP upgrade',
  footer: 'Footer',
}

export function LandingPageBuilderPage() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const queryClient = useQueryClient()
  const { data, isPending } = useQuery(getLandingPage(projectId))

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'landing-page'] })

  const publish = useMutation({
    mutationFn: (published: boolean) => updateLandingPage(projectId, { published }),
    onSuccess: invalidate,
  })

  if (isPending || !data) {
    return (
      <>
        <PageHeader title="Landing page" />
        <div className="flex flex-col gap-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-16" />
          ))}
        </div>
      </>
    )
  }

  const page = data.landing_page

  return (
    <>
      <PageHeader title="Landing page" subtitle="Configuration over freedom — every section is conversion-tested">
        <div className="flex items-center gap-3">
          {page.published ? <Badge variant="success">Live</Badge> : <Badge variant="outline">Draft</Badge>}
          <Button
            variant={page.published ? 'outline' : 'default'}
            onClick={() => publish.mutate(!page.published)}
            disabled={publish.isPending}
          >
            {page.published ? 'Unpublish' : 'Publish'}
          </Button>
        </div>
      </PageHeader>

      {page.published && (
        <p className="mb-4 flex items-center gap-1.5 text-sm text-muted-foreground">
          <ExternalLink className="h-3.5 w-3.5" aria-hidden />
          Served at <code className="rounded bg-muted px-1.5 py-0.5 text-xs">/api/pages/{page.slug}</code>
        </p>
      )}

      <div className="flex flex-col gap-3">
        {page.sections.map((section) => (
          <SectionCard key={section.id} projectId={projectId} section={section} onSaved={invalidate} />
        ))}
      </div>
    </>
  )
}

function SectionCard({ projectId, section, onSaved }: {
  projectId: string
  section: Section
  onSaved: () => void
}) {
  const [open, setOpen] = useState(false)
  const [content, setContent] = useState<Record<string, unknown>>(section.content ?? {})

  const toggle = useMutation({
    mutationFn: (enabled: boolean) => updateSections(projectId, [{ id: section.id, enabled }]),
    onSuccess: onSaved,
  })

  const save = useMutation({
    mutationFn: () => updateSections(projectId, [{ id: section.id, content }]),
    onSuccess: onSaved,
  })

  const editableFields = Object.entries(content).filter(
    ([, value]) => typeof value === 'string' || typeof value === 'number',
  )

  const label = SECTION_LABELS[section.type] ?? section.type

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <button
          type="button"
          className="text-left"
          onClick={() => setOpen((o) => !o)}
          aria-expanded={open}
        >
          <CardTitle>{label}</CardTitle>
          {!open && <CardDescription className="mt-1">Click to edit content</CardDescription>}
        </button>
        <Switch
          checked={section.enabled}
          onCheckedChange={(enabled) => toggle.mutate(enabled)}
          aria-label={`${section.enabled ? 'Disable' : 'Enable'} ${label} section`}
          disabled={toggle.isPending}
        />
      </CardHeader>
      {open && (
        <CardContent className="flex flex-col gap-3 border-t border-border pt-4">
          {editableFields.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              This section has no editable text yet — list content is coming soon.
            </p>
          ) : (
            editableFields.map(([field, value]) => (
              <div key={field} className="flex flex-col gap-1.5">
                <Label htmlFor={`s${section.id}-${field}`}>
                  {field.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase())}
                </Label>
                <Input
                  id={`s${section.id}-${field}`}
                  value={String(value)}
                  onChange={(e) => setContent((c) => ({ ...c, [field]: e.target.value }))}
                />
              </div>
            ))
          )}
          {editableFields.length > 0 && (
            <Button size="sm" className="self-start" onClick={() => save.mutate()} disabled={save.isPending}>
              {save.isPending ? 'Saving…' : save.isSuccess ? 'Saved' : 'Save section'}
            </Button>
          )}
        </CardContent>
      )}
    </Card>
  )
}
