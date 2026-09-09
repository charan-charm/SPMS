import { Link } from 'react-router'

/**
 * F1 foundation placeholder — clearly marked scaffolding, not an SPMS
 * product page. Replaced by real pages in F2 (design system), F3
 * (application shell), F4 (API boundary), and F5 (landing page).
 */
export function RouteErrorBoundary() {
    return (
        <main className="min-h-screen grid place-items-center bg-background px-6">
            <div className="max-w-md rounded-lg border border-border bg-surface p-6 text-center shadow-sm">
                <p className="text-sm font-semibold uppercase tracking-wide text-muted">
                    Frontend foundation
                </p>
                <h1 className="mt-2 font-display text-xl font-semibold text-heading">
                    Something went wrong rendering this route.
                </h1>
                <p className="mt-2 text-sm text-body">
                    This is the F1 route error boundary placeholder.
                </p>
                <Link
                    to="/"
                    className="mt-4 inline-block rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-contrast"
                >
                    Back to home
                </Link>
            </div>
        </main>
    )
}
