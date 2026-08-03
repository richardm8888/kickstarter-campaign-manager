import { Link, Outlet, useParams } from '@tanstack/react-router'
import { useMutation, useQuery } from '@tanstack/react-query'
import {
  Activity,
  BarChart3,
  Brain,
  Cable,
  HeartPulse,
  LayoutDashboard,
  LogOut,
  Megaphone,
  PanelsTopLeft,
  Rocket,
  Settings,
  TrendingUp,
} from 'lucide-react'
import { getProject } from '@/features/projects/api'
import { logout } from '@/features/auth/api'
import { ThemeToggle } from './ThemeToggle'

const NAV = [
  { to: '/projects/$projectId', label: 'Dashboard', icon: LayoutDashboard, exact: true },
  { to: '/projects/$projectId/analytics', label: 'Analytics', icon: BarChart3 },
  { to: '/projects/$projectId/ads', label: 'Ads', icon: Megaphone },
  { to: '/projects/$projectId/insights', label: 'AI insights', icon: Brain },
  { to: '/projects/$projectId/health', label: 'Campaign health', icon: HeartPulse },
  { to: '/projects/$projectId/forecast', label: 'Forecast', icon: TrendingUp },
  { to: '/projects/$projectId/landing-page', label: 'Landing page', icon: PanelsTopLeft },
  { to: '/projects/$projectId/integrations', label: 'Integrations', icon: Cable },
  { to: '/projects/$projectId/settings', label: 'Settings', icon: Settings },
] as const

export function ProjectLayout() {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { data } = useQuery(getProject(projectId))

  const logoutMutation = useMutation({
    mutationFn: logout,
    onSuccess: () => location.assign('/login'),
  })

  return (
    <div className="flex min-h-screen flex-col md:flex-row">
      <aside className="flex shrink-0 flex-col border-b border-border bg-card md:h-screen md:w-60 md:border-b-0 md:border-r md:sticky md:top-0">
        <div className="flex items-center gap-2.5 px-4 py-4">
          <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
            <Rocket className="h-4 w-4" aria-hidden />
          </span>
          <div className="min-w-0">
            <Link to="/projects" className="block text-xs text-muted-foreground hover:text-foreground">
              Launch OS
            </Link>
            <p className="truncate text-sm font-semibold">{data?.project.name ?? '…'}</p>
          </div>
        </div>
        <nav aria-label="Project" className="flex gap-1 overflow-x-auto px-3 pb-3 md:flex-1 md:flex-col md:overflow-visible">
          {NAV.map(({ to, label, icon: Icon, ...opts }) => (
            <Link
              key={to}
              to={to}
              params={{ projectId }}
              activeOptions={{ exact: 'exact' in opts && opts.exact }}
              className="flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
              activeProps={{ className: 'bg-muted text-foreground font-medium' }}
            >
              <Icon className="h-4 w-4" aria-hidden />
              {label}
            </Link>
          ))}
        </nav>
        <div className="hidden items-center justify-between border-t border-border px-3 py-3 md:flex">
          <button
            type="button"
            onClick={() => logoutMutation.mutate()}
            className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          >
            <LogOut className="h-4 w-4" aria-hidden />
            Sign out
          </button>
          <ThemeToggle />
        </div>
      </aside>
      <main className="min-w-0 flex-1 px-4 py-6 md:px-8 md:py-8">
        <div className="mx-auto max-w-5xl">
          <Outlet />
        </div>
      </main>
    </div>
  )
}

export function PageHeader({ title, subtitle, children }: {
  title: string
  subtitle?: string
  children?: React.ReactNode
}) {
  return (
    <header className="mb-6 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>}
      </div>
      {children}
    </header>
  )
}

export function EmptyState({ icon: Icon = Activity, title, body }: {
  icon?: React.ComponentType<{ className?: string; 'aria-hidden'?: boolean }>
  title: string
  body: string
}) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border py-14 text-center">
      <Icon className="h-6 w-6 text-muted-foreground" aria-hidden />
      <p className="text-sm font-medium">{title}</p>
      <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
    </div>
  )
}
