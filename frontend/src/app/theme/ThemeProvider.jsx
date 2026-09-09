import { useCallback, useEffect, useMemo, useState } from 'react'
import { ThemeContext } from './ThemeContext'

const STORAGE_KEY = 'spms-theme'

/**
 * Theme foundation (F1, extended in F3): light | dark | system.
 *
 * - `preference` is what the user chose (or 'system' by default)
 * - `resolvedTheme` is the theme actually applied
 * - system preference changes are followed while preference is 'system'
 *
 * Single theme mechanism — no other component may manage theme state.
 */

const media = window.matchMedia('(prefers-color-scheme: dark)')

function getSystemTheme() {
    return media.matches ? 'dark' : 'light'
}

function resolveInitialPreference() {
    try {
        const stored = localStorage.getItem(STORAGE_KEY)
        if (stored === 'light' || stored === 'dark' || stored === 'system') {
            return stored
        }
    } catch {
        // Storage unavailable — fall through to system preference.
    }
    return 'system'
}

function applyTheme(resolved) {
    document.documentElement.setAttribute('data-theme', resolved)
}

function persist(preference) {
    try {
        localStorage.setItem(STORAGE_KEY, preference)
    } catch {
        // Storage unavailable — preference applies for this session only.
    }
}

export function ThemeProvider({ children }) {
    const [preference, setPreference] = useState(resolveInitialPreference)
    const [systemTheme, setSystemTheme] = useState(getSystemTheme)

    const resolvedTheme = preference === 'system' ? systemTheme : preference

    useEffect(() => {
        applyTheme(resolvedTheme)
    }, [resolvedTheme])

    useEffect(() => {
        if (preference !== 'system') {
            return undefined
        }
        const onChange = (event) => setSystemTheme(event.matches ? 'dark' : 'light')
        media.addEventListener('change', onChange)
        return () => media.removeEventListener('change', onChange)
    }, [preference])

    const setTheme = useCallback((next) => {
        const normalized = next === 'light' || next === 'dark' || next === 'system' ? next : 'system'
        persist(normalized)
        setPreference(normalized)
    }, [])

    const toggleTheme = useCallback(() => {
        setPreference((current) => {
            const next = current === 'light' ? 'dark' : current === 'dark' ? 'system' : 'light'
            persist(next)
            return next
        })
    }, [])

    const value = useMemo(
        () => ({ theme: preference, resolvedTheme, setTheme, toggleTheme }),
        [preference, resolvedTheme, setTheme, toggleTheme],
    )

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}
