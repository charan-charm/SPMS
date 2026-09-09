import { createContext } from 'react'

/**
 * Theme context shared by ThemeProvider and useTheme.
 * Kept in its own module so both import from a stable location.
 */
export const ThemeContext = createContext(null)
