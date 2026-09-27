import { createBrowserRouter } from 'react-router'
import { FoundationPlaceholder } from '../pages/public/FoundationPlaceholder'
import { DashboardPage } from '../pages/app/DashboardPage'
import { EnergyBalancePage } from '../pages/app/EnergyBalancePage'
import { EnergyCostPage } from '../pages/app/EnergyCostPage'
import { InsightsPage } from '../pages/app/InsightsPage'
import { RecommendationsPage } from '../pages/app/RecommendationsPage'
import { BillsPage } from '../pages/app/BillsPage'
import { SolarPage } from '../pages/app/SolarPage'
import { ForecastsPage } from '../pages/app/ForecastsPage'
import { SettingsPage } from '../pages/app/SettingsPage'
import { ReportsPage } from '../pages/app/ReportsPage'
import { NotificationsPage } from '../pages/app/NotificationsPage'
import { AnalyticsPage } from '../pages/app/AnalyticsPage'
import { RouteErrorBoundary } from './RouteErrorBoundary'
import { publicRoutes, protectedRoutes, authRoutes } from './routes'
import { PublicLayout } from '../layouts/PublicLayout'
import { AuthLayout } from '../layouts/AuthLayout'
import { AppLayout } from '../layouts/AppLayout'
import { RootLayout } from '../layouts/RootLayout'
import { DesignSystemPreview } from '../pages/public/DesignSystemPreview'
import { LandingPage } from '../pages/public/LandingPage'
import { DashboardShellPreview } from '../pages/public/DashboardShellPreview'
import { LoadingStateDemo } from '../pages/public/LoadingStateDemo'
import { BillsWorkspacePreview } from '../pages/public/BillsWorkspacePreview'
import { ProtectedRoute } from '../features/auth/ProtectedRoute'
import { PublicAuthRoute } from '../features/auth/PublicAuthRoute'
import { LoginPage } from '../pages/auth/LoginPage'
import { RegisterPage } from '../pages/auth/RegisterPage'
import { VerifyPage } from '../pages/auth/VerifyPage'
import { RecoverPage } from '../pages/auth/RecoverPage'


import { OnboardingLayout } from '../layouts/OnboardingLayout'
import { OnboardingPage } from '../pages/onboarding/OnboardingPage'

function placeholderRoute(path, name) {
    return {
        path,
        element: <FoundationPlaceholder routeName={name} path={path} />,
    }
}

function authElementFor(path, name) {
    switch (path) {
        case '/login':
            return <LoginPage />
        case '/register':
            return <RegisterPage />
        case '/verify':
            return <VerifyPage />
        case '/recover':
            return <RecoverPage />
        default:
            return placeholderRoute(path, name)
    }
}

function baseRoutes() {
    return [
        {
            element: <PublicLayout />,
            children: [
                ...publicRoutes.map((route) =>
                    route.path === '/'
                        ? { path: route.path, element: <LandingPage /> }
                        : placeholderRoute(route.path, route.name),
                ),
                {
                    path: '*',
                    element: <FoundationPlaceholder routeName="Not found" path="*" notFound />,
                },
            ],
        },
        {
            element: (
                <PublicAuthRoute>
                    <AuthLayout />
                </PublicAuthRoute>
            ),
            children: authRoutes.map((route) => ({
                path: route.path,
                element: authElementFor(route.path, route.name),
            })),
        },
        {
            element: (
                <ProtectedRoute>
                    <OnboardingLayout />
                </ProtectedRoute>
            ),
            children: [
                {
                    path: '/onboarding',
                    element: <OnboardingPage />,
                },
            ],
        },
        {
            element: (
                <ProtectedRoute requireHousehold>
                    <AppLayout />
                </ProtectedRoute>
            ),
            children: protectedRoutes.map((route) =>
                route.path === '/dashboard'
                    ? { path: route.path, element: <DashboardPage /> }
                    : route.path === '/energy'
                        ? { path: route.path, element: <EnergyBalancePage /> }
                    : route.path === '/energy/cost'
                        ? { path: route.path, element: <EnergyCostPage /> }
                    : route.path === '/insights'
                        ? { path: route.path, element: <InsightsPage /> }
                    : route.path === '/recommendations'
                        ? { path: route.path, element: <RecommendationsPage /> }
                        : route.path === '/bills'
                            ? { path: route.path, element: <BillsPage /> }
                            : route.path === '/solar'
                                ? { path: route.path, element: <SolarPage /> }
                                : route.path === '/forecasts'
                                    ? { path: route.path, element: <ForecastsPage /> }
                                    : route.path === '/settings'
                                        ? { path: route.path, element: <SettingsPage /> }
                                        : route.path === '/reports'
                                            ? { path: route.path, element: <ReportsPage /> }
                                            : route.path === '/notifications'
                                                ? { path: route.path, element: <NotificationsPage /> }
                                                : route.path === '/analytics'
                                                    ? { path: route.path, element: <AnalyticsPage /> }
                                                    : placeholderRoute(route.path, route.name),
            ),
        },
    ]
}

export const router = createBrowserRouter([
    {
        element: <RootLayout />,
        errorElement: <RouteErrorBoundary />,
        children: baseRoutes(),
    },
])

// Dev-only preview router (F2/F3). Public and application routes —
// including the O1 landing page at / — render through baseRoutes;
// design verification pages live under /dev/*. This branch is dead
// code in production builds.
const devRouter = import.meta.env.DEV
    ? createBrowserRouter([
          {
              element: <RootLayout />,
              errorElement: <RouteErrorBoundary />,
              children: [
                  {
                      element: <AppLayout />,
                      children: [
                          { path: '/dev/dashboard', element: <DashboardShellPreview /> },
                          { path: '/dev/loading', element: <LoadingStateDemo /> },
                          { path: '/dev/bills', element: <BillsWorkspacePreview /> },
                      ],
                  },
                  ...baseRoutes(),
                  { path: '/dev/design-system', element: <DesignSystemPreview /> },
              ],
          },
      ])
    : router

export { devRouter }
