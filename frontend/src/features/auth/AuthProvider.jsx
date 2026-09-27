import { useEffect, useState, useCallback } from 'react'
import { AuthContext } from './AuthContext'
import { loadServerSession } from '../../services/api/session'
import { authApi } from '../../services/api/auth'

// In-flight promise to deduplicate session bootstrapping in React StrictMode
let sessionBootstrapPromise = null

export function AuthProvider({ children }) {
    const [status, setStatus] = useState('loading') // 'loading' | 'authenticated' | 'unauthenticated' | 'error'
    const [account, setAccount] = useState(null)
    const [error, setError] = useState(null)

    const restoreSession = useCallback(async (force = false) => {
        if (!force && sessionBootstrapPromise) {
            return sessionBootstrapPromise
        }

        const promise = (async () => {
            try {
                const result = await loadServerSession()
                if (result.authenticated && result.account) {
                    setAccount(result.account)
                    setStatus('authenticated')
                    setError(null)
                    return result
                }
                setAccount(null)
                setStatus('unauthenticated')
                setError(null)
                return result
            } catch (err) {
                setAccount(null)
                setStatus('error')
                setError(err)
                throw err
            } finally {
                sessionBootstrapPromise = null
            }
        })()

        sessionBootstrapPromise = promise
        return promise
    }, [])

    useEffect(() => {
        let mounted = true
        restoreSession().catch(() => {
            if (mounted) {
                // Error state is already captured inside restoreSession
            }
        })
        return () => {
            mounted = false
        }
    }, [restoreSession])

    const login = useCallback(async ({ email, password, environment }) => {
        const data = await authApi.login({ email, password, environment })
        if (data?.account) {
            setAccount(data.account)
            setStatus('authenticated')
            setError(null)
        }
        return data
    }, [])

    const logout = useCallback(async () => {
        try {
            await authApi.logout()
        } finally {
            setAccount(null)
            setStatus('unauthenticated')
            setError(null)
        }
    }, [])

    const value = {
        status,
        account,
        error,
        isLoading: status === 'loading',
        isAuthenticated: status === 'authenticated',
        login,
        logout,
        refreshSession: () => restoreSession(true),
    }

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
