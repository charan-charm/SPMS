import { createContext } from 'react'

/**
 * SolarContext (SPMS O4.7 — docs/10 D-059, D-060, D-061).
 *
 * Exposes backend-authoritative solar provider connection state,
 * normalized status, and synchronization actions to the application.
 */
export const SolarContext = createContext(null)
