import { useState, useEffect, useCallback, useRef } from 'react'
import { SolarContext } from './SolarContext'
import { solarApi } from '../../services/api/solar'
import { useAuth } from '../auth/useAuth'
import { useHousehold } from '../household/useHousehold'
import { deriveNormalizedStatus } from './solarStatus'

let solarBootstrapPromise = null

export function SolarProvider({ children }) {
    const { isAuthenticated, status: authStatus } = useAuth()
    const { hasHousehold, status: householdStatus } = useHousehold()
    const [connection, setConnection] = useState(null)
    const [isLoading, setIsLoading] = useState(false)
    const [error, setError] = useState(null)
    const mountedRef = useRef(true)

    const fetchConnection = useCallback(async (signal = undefined) => {
        if (solarBootstrapPromise) {
            return solarBootstrapPromise
        }

        const promise = (async () => {
            setIsLoading(true)
            try {
                const resp = await solarApi.getConnection(signal)
                if (mountedRef.current) {
                    const conn = resp?.connection ?? null
                    setConnection(conn)
                    setError(null)
                }
                return resp?.connection ?? null
            } catch (err) {
                if (mountedRef.current && err.name !== 'AbortError') {
                    setConnection(null)
                    setError(err)
                }
                return null
            } finally {
                if (mountedRef.current) {
                    setIsLoading(false)
                }
                solarBootstrapPromise = null
            }
        })()

        solarBootstrapPromise = promise
        return promise
    }, [])

    useEffect(() => {
        mountedRef.current = true

        if (isAuthenticated && hasHousehold) {
            fetchConnection().catch(() => {})
        } else if (authStatus === 'unauthenticated' || (!hasHousehold && householdStatus === 'loaded')) {
            Promise.resolve().then(() => {
                if (mountedRef.current) {
                    setConnection(null)
                    setIsLoading(false)
                    setError(null)
                }
            })
        }

        return () => {
            mountedRef.current = false
        }
    }, [isAuthenticated, hasHousehold, authStatus, householdStatus, fetchConnection])

    const updateConnection = useCallback((newConnection) => {
        setConnection(newConnection)
    }, [])

    const connectionStatus = deriveNormalizedStatus(connection)

    const value = {
        connection,
        connectionStatus,
        isLoading,
        error,
        refreshConnection: fetchConnection,
        updateConnection,
    }

    return (
        <SolarContext.Provider value={value}>
            {children}
        </SolarContext.Provider>
    )
}
