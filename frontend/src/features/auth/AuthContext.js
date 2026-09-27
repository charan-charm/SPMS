import { createContext } from 'react'

/**
 * Authentication context for SPMS session state.
 * Kept in its own module so AuthProvider and useAuth can import without cyclic dependency.
 */
export const AuthContext = createContext(null)
export { useAuth } from './useAuth'
