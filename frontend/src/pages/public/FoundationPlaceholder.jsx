import { Link } from 'react-router'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '../../components/ui/card'
import { publicRoutes, authRoutes, protectedRoutes } from '../../app/routes'

/**
 * F1 foundation placeholder — clearly marked scaffolding, not an SPMS
 * product page. Rendered inside the F3 layout shells so the shell can
 * be verified around it. Replaced by real pages in F4/F5 and the
 * domain milestones.
 */
export function FoundationPlaceholder({ routeName, path, notFound = false }) {
    return (
        <Card className="mx-auto w-full max-w-3xl">
            <CardHeader>
                <p className="text-xs font-semibold uppercase tracking-wide text-secondary">
                    SPMS frontend foundation
                </p>
                {notFound ? (
                    <>
                        <CardTitle>Page not found</CardTitle>
                        <CardDescription>
                            No route exists for <code className="rounded bg-surface-alt px-1">{path}</code>.
                        </CardDescription>
                    </>
                ) : (
                    <>
                        <CardTitle>{routeName}</CardTitle>
                        <CardDescription>
                            Route <code className="rounded bg-surface-alt px-1">{path}</code> renders
                            inside its correct layout shell. The actual page is intentionally not
                            implemented yet.
                        </CardDescription>
                    </>
                )}
            </CardHeader>

            <CardContent>
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted">
                    Registered routes
                </p>
                <div className="grid gap-4 sm:grid-cols-3">
                    <div>
                        <p className="text-xs font-medium text-muted">Public</p>
                        <ul className="mt-1 space-y-1">
                            {publicRoutes.map((route) => (
                                <li key={route.path}>
                                    <Link
                                        to={route.path}
                                        className="text-sm text-secondary hover:underline"
                                    >
                                        {route.path} — {route.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div>
                        <p className="text-xs font-medium text-muted">Auth</p>
                        <ul className="mt-1 space-y-1">
                            {authRoutes.map((route) => (
                                <li key={route.path}>
                                    <Link
                                        to={route.path}
                                        className="text-sm text-secondary hover:underline"
                                    >
                                        {route.path} — {route.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div>
                        <p className="text-xs font-medium text-muted">Protected (no guards yet)</p>
                        <ul className="mt-1 space-y-1">
                            {protectedRoutes.map((route) => (
                                <li key={route.path}>
                                    <Link
                                        to={route.path}
                                        className="text-sm text-secondary hover:underline"
                                    >
                                        {route.path} — {route.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>

                <p className="mt-5 text-xs text-muted">
                    React presents backend-authoritative data only. The PHP REST API
                    (/api/v1) remains the sole authority for business and analytical truth.
                </p>
            </CardContent>
        </Card>
    )
}
