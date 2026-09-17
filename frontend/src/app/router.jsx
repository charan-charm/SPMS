import { createBrowserRouter } from 'react-router'
import { FoundationPlaceholder } from '../pages/public/FoundationPlaceholder'
import { EnergyBalancePage } from '../pages/app/EnergyBalancePage'
import { EnergyCostPage } from '../pages/app/EnergyCostPage'
import { InsightsPage } from '../pages/app/InsightsPage'
import { BillsPage } from '../pages/app/BillsPage'
import { RouteErrorBoundary } from './RouteErrorBoundary'
import { publicRoutes, protectedRoutes, authRoutes } from './routes'
import { PublicLayout } from '../layouts/PublicLayout'
import { AuthLayout } from '../layouts/AuthLayout'
import { AppLayout } from '../layouts/AppLayout'
import { DesignSystemPreview } from '../pages/public/DesignSystemPreview'
import { LandingPage } from '../pages/public/LandingPage'
import { DashboardShellPreview } from '../pages/public/DashboardShellPreview'
import { LoadingStateDemo } from '../pages/public/LoadingStateDemo'
import { BillsWorkspacePreview } from '../pages/public/BillsWorkspacePreview'

/**
 * Routing foundation integrated with the F3 application shell (docs/03 §4,
 * docs/11 §9). Public routes render in PublicLayout, authentication
 * routes in AuthLayout, protected application routes in AppLayout.
 *
 * Route guards are NOT implemented here — guards are navigation UX and
 * backend authorization remains authoritative (F4/authentication
 * milestones will add session-aware navigation).
 */

function placeholderRoute(path, name) {
    return {
        path,
        element: <FoundationPlaceholder routeName={name} path={path} />,
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
            element: <AuthLayout />,
            children: authRoutes.map((route) => placeholderRoute(route.path, route.name)),
        },
        {
            element: <AppLayout />,
            children: protectedRoutes.map((route) =>
                route.path === '/energy'
                    ? { path: route.path, element: <EnergyBalancePage /> }
                    : route.path === '/energy/cost'
                        ? { path: route.path, element: <EnergyCostPage /> }
                    : route.path === '/insights'
                        ? { path: route.path, element: <InsightsPage /> }
                        : route.path === '/bills'
                            ? { path: route.path, element: <BillsPage /> }
                            : placeholderRoute(route.path, route.name),
            ),
        },
    ]
}

export const router = createBrowserRouter([
    {
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
