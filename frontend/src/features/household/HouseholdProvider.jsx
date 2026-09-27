import { useState, useEffect, useCallback } from 'react'
import { HouseholdContext } from './HouseholdContext'
import { householdApi } from '../../services/api/household'
import { useAuth } from '../auth/useAuth'

let householdBootstrapPromise = null

export function HouseholdProvider({ children }) {
    const { isAuthenticated, status: authStatus } = useAuth()
    const [household, setHousehold] = useState(null)
    const [membership, setMembership] = useState(null)
    const [readiness, setReadiness] = useState(null)
    const [status, setStatus] = useState('idle') // 'idle' | 'loading' | 'loaded' | 'error'
    const [error, setError] = useState(null)

    const fetchHousehold = useCallback(async () => {
        if (householdBootstrapPromise) {
            return householdBootstrapPromise
        }

        const promise = (async () => {
            try {
                const data = await householdApi.getHousehold()
                setHousehold(data?.household ?? null)
                setMembership(data?.membership ?? null)
                setReadiness(data?.readiness ?? null)
                setStatus('loaded')
                setError(null)
                return data
            } catch (err) {
                setHousehold(null)
                setMembership(null)
                setReadiness(null)
                setStatus('error')
                setError(err)
                return null
            } finally {
                householdBootstrapPromise = null
            }
        })()

        householdBootstrapPromise = promise
        return promise
    }, [])

    useEffect(() => {
        let mounted = true
        if (isAuthenticated) {
            fetchHousehold().catch(() => {
                if (mounted) {
                    // Handled inside fetchHousehold
                }
            })
        } else if (authStatus === 'unauthenticated') {
            Promise.resolve().then(() => {
                if (mounted) {
                    setHousehold(null)
                    setMembership(null)
                    setReadiness(null)
                    setStatus('idle')
                    setError(null)
                }
            })
        }
        return () => {
            mounted = false
        }
    }, [isAuthenticated, authStatus, fetchHousehold])

    const createHousehold = useCallback(async (payload) => {
        const result = await householdApi.createHousehold(payload)
        if (result?.household) {
            setHousehold(result.household)
            setMembership(result.membership ?? null)
            setStatus('loaded')
            fetchHousehold().catch(() => {})
        }
        return result
    }, [fetchHousehold])

    const ensureDemo = useCallback(async () => {
        const result = await householdApi.ensureDemoHousehold()
        if (result?.household) {
            setHousehold(result.household)
            setMembership(result.membership ?? null)
            setReadiness(result.readiness ?? null)
            setStatus('loaded')
        }
        return result
    }, [])

    const updateHousehold = useCallback(async (changes) => {
        const result = await householdApi.updateHousehold(changes)
        if (result?.household) {
            setHousehold(result.household)
            if (result.membership) {
                setMembership(result.membership)
            }
            await fetchHousehold().catch(() => {})
        }
        return result
    }, [fetchHousehold])

    const value = {
        household,
        membership,
        readiness,
        status,
        error,
        isLoading: status === 'loading',
        hasHousehold: Boolean(household),
        refreshHousehold: fetchHousehold,
        createHousehold,
        ensureDemo,
        updateHousehold,
    }

    return (
        <HouseholdContext.Provider value={value}>
            {children}
        </HouseholdContext.Provider>
    )
}
