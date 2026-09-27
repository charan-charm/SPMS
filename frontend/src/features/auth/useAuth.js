import { useContext } from 'react'
import { AuthContext } from './AuthContext'

const defaultAuth = {
    status: 'unauthenticated',
    account: null,
    error: null,
    isLoading: false,
    isAuthenticated: false,
    login: async () => {},
    logout: async () => {},
    refreshSession: async () => {},
}

/**
 * Access the memory-based SPMS authentication session state.
 * Gracefully provides safe unauthenticated defaults if rendered outside AuthProvider.
 */
export function useAuth() {
    const context = useContext(AuthContext)
    return context ?? defaultAuth
}
