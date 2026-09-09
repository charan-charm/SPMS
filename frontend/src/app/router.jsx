import { createBrowserRouter } from 'react-router'
import { FoundationPlaceholder } from '../pages/public/FoundationPlaceholder'
import { EnergyBalancePage } from '../pages/app/EnergyBalancePage'
import { RouteErrorBoundary } from './RouteErrorBoundary'
import { publicRoutes, protectedRoutes, authRoutes } from './routes'
import { PublicLayout } from '../layouts/PublicLayout'
import { AuthLayout } from '../layouts/AuthLayout'
import { AppLayout } from '../layouts/AppLayout'
import { DesignSystemPreview } from '../pages/public/DesignSystemPreview'
import { DashboardShellPreview } from '../pages/public/DashboardShellPreview'

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
            children: publicRoutes.map((route) => placeholderRoute(route.path, route.name)),
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
                    : placeholderRoute(route.path, route.name),
            ),
        },
        {
            path: '*',
            element: (
                <PublicLayout>
                    <FoundationPlaceholder routeName="Not found" path="*" notFound />
                </PublicLayout>
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

// Dev-only preview router (F2/F3). The root renders the design-system
// verification page; /dev/dashboard renders the dashboard shell preview
// inside the real AppLayout. This branch is dead code in production builds.
const devRouter = import.meta.env.DEV
    ? createBrowserRouter([
          {
              errorElement: <RouteErrorBoundary />,
              children: [
                  { path: '/', element: <DesignSystemPreview /> },
                  {
                      element: <AppLayout />,
                      children: [{ path: '/dev/dashboard', element: <DashboardShellPreview /> }],
                  },
                  ...baseRoutes(),
              ],
          },
      ])
    : router

export { devRouter }
