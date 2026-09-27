import { Navigate, Outlet, useLocation } from 'react-router'
import { useAuth } from './useAuth'
import { useHousehold } from '../household/useHousehold'
import { LoadingState } from '../../components/feedback/LoadingState'

/**
 * ProtectedRoute guard (docs/08 §18, docs/11 §9, docs/10 D-050/D-065/D-071).
 *
 * Requirements:
 * - Unknown/loading session: renders a neutral auth loading indicator.
 * - Unauthenticated: redirects to /login and preserves intended destination in location.state.from.
 * - Authenticated without household (on requireHousehold routes): redirects to /onboarding.
 * - Authenticated with active household (on /onboarding): redirects to /dashboard.
 * - Authenticated with valid household context: renders child route via <Outlet />.
 */
export function ProtectedRoute({ children, requireHousehold = false }) {
    const { status } = useAuth()
    const { isLoading: householdLoading, hasHousehold, status: householdStatus } = useHousehold()
    const location = useLocation()

    if (status === 'loading') {
        return (
            <div className="flex min-h-[60vh] flex-col items-center justify-center gap-3" aria-busy="true">
                <LoadingState label="Restoring your session" />
            </div>
        )
    }

    if (status === 'unauthenticated') {
        const destination = location.pathname + location.search
        return <Navigate to="/login" state={{ from: destination }} replace />
    }

    // Require completed household onboarding before entering the workspace
    if (requireHousehold) {
        if (householdLoading || householdStatus === 'idle') {
            return (
                <div className="flex min-h-[60vh] flex-col items-center justify-center gap-3" aria-busy="true">
                    <LoadingState label="Loading household workspace..." />
                </div>
            )
        }

        if (!hasHousehold) {
            return <Navigate to="/onboarding" replace />
        }
    }

    // Redirect away from /onboarding if household already exists, unless actively in guided setup or resuming
    const searchParams = new URLSearchParams(location.search)
    const isContinuing = searchParams.has('continue') || searchParams.has('step') || searchParams.has('resume')
    if (location.pathname === '/onboarding' && hasHousehold && !householdLoading && !isContinuing) {
        return <Navigate to="/dashboard" replace />
    }

    return children ? children : <Outlet />
}
