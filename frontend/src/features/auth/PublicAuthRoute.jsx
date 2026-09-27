import { Navigate, Outlet, useLocation } from 'react-router'
import { useAuth } from './useAuth'
import { LoadingState } from '../../components/feedback/LoadingState'

/**
 * PublicAuthRoute guard.
 *
 * Prevents authenticated users from viewing login/register forms unnecessarily
 * and routes them to their intended destination or /dashboard.
 */
export function PublicAuthRoute({ children }) {
    const { status } = useAuth()
    const location = useLocation()

    if (status === 'loading') {
        return (
            <div className="flex min-h-[40vh] items-center justify-center" aria-busy="true">
                <LoadingState label="Checking session" />
            </div>
        )
    }

    if (status === 'authenticated') {
        const destination = location.state?.from || '/dashboard'
        return <Navigate to={destination} replace />
    }

    return children ? children : <Outlet />
}
