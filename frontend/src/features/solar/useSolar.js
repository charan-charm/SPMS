import { useContext } from 'react'
import { SolarContext } from './SolarContext'

const DEFAULT_SOLAR_CONTEXT = {
    connection: null,
    connectionStatus: 'NOT_CONFIGURED',
    isLoading: false,
    error: null,
    refreshConnection: async () => null,
    updateConnection: () => {},
}

/**
 * Hook to access authoritative solar provider connection state.
 * Gracefully provides safe unconfigured defaults if invoked outside SolarProvider.
 */
export function useSolar() {
    const context = useContext(SolarContext)
    return context ?? DEFAULT_SOLAR_CONTEXT
}
