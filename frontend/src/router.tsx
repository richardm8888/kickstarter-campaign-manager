import {
  createRootRoute,
  createRoute,
  createRouter,
  redirect,
} from '@tanstack/react-router'
import { getToken } from '@/lib/api'
import { LoginPage } from '@/features/auth/LoginPage'
import { RegisterPage } from '@/features/auth/RegisterPage'
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage'
import { ProjectsPage } from '@/features/projects/ProjectsPage'
import { SettingsPage } from '@/features/projects/SettingsPage'
import { ProjectLayout } from '@/components/layout/ProjectLayout'
import { DashboardPage } from '@/features/dashboard/DashboardPage'
import { AnalyticsPage } from '@/features/analytics/AnalyticsPage'
import { AdsPage } from '@/features/ads/AdsPage'
import { InsightsPage } from '@/features/ai/InsightsPage'
import { HealthPage } from '@/features/ai/HealthPage'
import { ForecastPage } from '@/features/forecasting/ForecastPage'
import { LandingPageBuilderPage } from '@/features/landing-page/LandingPageBuilderPage'
import { IntegrationsPage } from '@/features/integrations/IntegrationsPage'

const rootRoute = createRootRoute()

const requireAuth = () => {
  if (!getToken()) throw redirect({ to: '/login' })
}

const requireGuest = () => {
  if (getToken()) throw redirect({ to: '/projects' })
}

const indexRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/',
  beforeLoad: () => {
    throw redirect({ to: getToken() ? '/projects' : '/login' })
  },
})

const loginRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/login',
  beforeLoad: requireGuest,
  component: LoginPage,
})

const registerRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/register',
  beforeLoad: requireGuest,
  component: RegisterPage,
})

const forgotPasswordRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/forgot-password',
  beforeLoad: requireGuest,
  component: ForgotPasswordPage,
})

const projectsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/projects',
  beforeLoad: requireAuth,
  component: ProjectsPage,
})

const projectLayoutRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/projects/$projectId',
  beforeLoad: requireAuth,
  component: ProjectLayout,
})

const dashboardRoute = createRoute({
  getParentRoute: () => projectLayoutRoute,
  path: '/',
  component: DashboardPage,
})

const analyticsRoute = createRoute({
  getParentRoute: () => projectLayoutRoute,
  path: '/analytics',
  component: AnalyticsPage,
})

const adsRoute = createRoute({
  getParentRoute: () => projectLayoutRoute,
  path: '/ads',
  component: AdsPage,
})

const insightsRoute = createRoute({
  getParentRoute: () => projectLayoutRoute,
  path: '/insights',
  component: InsightsPage,
})

const healthRoute = createRoute({
  getParentRoute: () => projectLayoutRoute,
  path: '/health',
  component: HealthPage,
})

const forecastRoute = createRoute({
  getParentRoute: () => projectLayoutRoute,
  path: '/forecast',
  component: ForecastPage,
})

const landingPageRoute = createRoute({
  getParentRoute: () => projectLayoutRoute,
  path: '/landing-page',
  component: LandingPageBuilderPage,
})

const integrationsRoute = createRoute({
  getParentRoute: () => projectLayoutRoute,
  path: '/integrations',
  component: IntegrationsPage,
})

const settingsRoute = createRoute({
  getParentRoute: () => projectLayoutRoute,
  path: '/settings',
  component: SettingsPage,
})

const routeTree = rootRoute.addChildren([
  indexRoute,
  loginRoute,
  registerRoute,
  forgotPasswordRoute,
  projectsRoute,
  projectLayoutRoute.addChildren([
    dashboardRoute,
    analyticsRoute,
    adsRoute,
    insightsRoute,
    healthRoute,
    forecastRoute,
    landingPageRoute,
    integrationsRoute,
    settingsRoute,
  ]),
])

export const router = createRouter({ routeTree })

declare module '@tanstack/react-router' {
  interface Register {
    router: typeof router
  }
}
