import { useContext } from 'react'
import { ThemeContext } from '../app/theme/ThemeContext'

/**
 * Access the SPMS theme foundation.
 *
 * Returns:
 * - theme: the user preference ('light' | 'dark' | 'system')
 * - resolvedTheme: the theme actually applied ('light' | 'dark')
 * - setTheme(preference): persist and apply a preference
 * - toggleTheme(): cycle light → dark → system
 */
export function useTheme() {
    const context = useContext(ThemeContext)

    if (!context) {
        throw new Error('useTheme must be used within ThemeProvider')
    }

    return context
}
