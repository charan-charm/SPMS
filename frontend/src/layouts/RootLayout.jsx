import { Outlet } from 'react-router'
import { AuthProvider } from '../features/auth/AuthProvider'
import { HouseholdProvider } from '../features/household/HouseholdProvider'
import { SolarProvider } from '../features/solar/SolarProvider'

/**
 * RootLayout: top-level shell for router hierarchies.
 * Hosts AuthProvider, HouseholdProvider, and SolarProvider so session state,
 * household context, and solar integration status are accessible throughout
 * public, auth, and protected layouts.
 */
export function RootLayout() {
    return (
        <AuthProvider>
            <HouseholdProvider>
                <SolarProvider>
                    <Outlet />
                </SolarProvider>
            </HouseholdProvider>
        </AuthProvider>
    )
}

