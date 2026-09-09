import { RouterProvider } from 'react-router'
import { MotionConfig } from 'motion/react'
import { router, devRouter } from './app/router'
import { ThemeProvider } from './app/theme/ThemeProvider'

/**
 * Application foundation. Wires the router, theme provider, and the
 * Motion reduced-motion policy. In development builds the root route
 * shows the F2 design-system verification page; production builds use
 * the approved route inventory only.
 */
export default function App() {
    const activeRouter = import.meta.env.DEV ? devRouter : router

    return (
        <ThemeProvider>
            <MotionConfig reducedMotion="user">
                <RouterProvider router={activeRouter} />
            </MotionConfig>
        </ThemeProvider>
    )
}
