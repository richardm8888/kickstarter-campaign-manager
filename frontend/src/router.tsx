import {
  createRootRoute,
  createRoute,
  createRouter,
  lazyRouteComponent,
  redirect,
} from '@tanstack/react-router'
import { getToken } from '@/lib/api'
import { ProjectLayout } from '@/components/layout/ProjectLayout'

/**
 * Each page is its own chunk.
 *
 * Everything used to be one 816 KB file, so opening the login screen
 * downloaded the charting library, the landing-page builder and every
 * dashboard behind it. Splitting costs a second request when you first
 * visit a page; defaultPreload below starts that request as soon as you
 * touch the link, which is earlier than you can finish tapping it.
 */
const LoginPage = lazyRouteComponent(() => import('@/features/auth/LoginPage'), 'LoginPage')
const RegisterPage = lazyRouteComponent(() => import('@/features/auth/RegisterPage'), 'RegisterPage')
const ForgotPasswordPage = lazyRouteComponent(() => import('@/features/auth/ForgotPasswordPage'), 'ForgotPasswordPage')
const ProjectsPage = lazyRouteComponent(() => import('@/features/projects/ProjectsPage'), 'ProjectsPage')
const SettingsPage = lazyRouteComponent(() => import('@/features/projects/SettingsPage'), 'SettingsPage')
const DashboardPage = lazyRouteComponent(() => import('@/features/dashboard/DashboardPage'), 'DashboardPage')
const AnalyticsPage = lazyRouteComponent(() => import('@/features/analytics/AnalyticsPage'), 'AnalyticsPage')
const AdsPage = lazyRouteComponent(() => import('@/features/ads/AdsPage'), 'AdsPage')
const DailyPage = lazyRouteComponent(() => import('@/features/daily/DailyPage'), 'DailyPage')
const InsightsPage = lazyRouteComponent(() => import('@/features/ai/InsightsPage'), 'InsightsPage')
const HealthPage = lazyRouteComponent(() => import('@/features/ai/HealthPage'), 'HealthPage')
const ForecastPage = lazyRouteComponent(() => import('@/features/forecasting/ForecastPage'), 'ForecastPage')
const LandingPageBuilderPage = lazyRouteComponent(() => import('@/features/landing-page/LandingPageBuilderPage'), 'LandingPageBuilderPage')
const IntegrationsPage = lazyRouteComponent(() => import('@/features/integrations/IntegrationsPage'), 'IntegrationsPage')

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

const dailyRoute = createRoute({
  getParentRoute: () => projectLayoutRoute,
  path: '/today',
  component: DailyPage,
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
    dailyRoute,
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

export const router = createRouter({
  routeTree,
  // Fetch a page's chunk on hover or touch-start, not on click.
  defaultPreload: 'intent',
})

declare module '@tanstack/react-router' {
  interface Register {
    router: typeof router
  }
}
