import { Link, useLocation } from 'react-router'
import { SpmsMark } from './BrandMark'
import { LANDING_NAV } from './public-nav'

/**
 * PublicFooter (O1 refinement §7) — a conventional full-width flat
 * footer: it spans the page edge to edge, belongs to the document
 * (no floating card, no rounded container, no shadow, no glass), and
 * keeps the same information — branding, a concise product
 * description, public navigation, Sign In / Get Started, and the
 * established copyright line. Edge padding mirrors the header.
 */
export function PublicFooter() {
    const { pathname } = useLocation()
    const isLanding = pathname === '/'

    return (
        <footer className="relative z-10 w-full border-t border-border bg-surface">
            <div className="w-full px-5 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-8 py-10 lg:flex-row lg:items-start lg:justify-between lg:gap-16">
                    <div className="max-w-md">
                        <div className="flex items-center gap-2.5">
                            <SpmsMark size={28} />
                            <p className="font-display text-sm font-semibold text-heading">
                                SPMS — Solar Power Management System
                            </p>
                        </div>
                        <p className="mt-3 text-sm text-body">
                            SPMS helps households understand their solar production and
                            electricity consumption, see upcoming energy conditions, and make
                            better use of the solar energy they generate. It advises — it does not
                            control appliances.
                        </p>
                    </div>

                    <nav aria-label="Footer" className="flex flex-col gap-2.5">
                        <p className="text-xs font-semibold uppercase tracking-wider text-muted">
                            Explore
                        </p>
                        <div className="flex flex-wrap gap-x-6 gap-y-2.5">
                            {isLanding &&
                                LANDING_NAV.map((item) => (
                                    <a
                                        key={item.id}
                                        href={`#${item.id}`}
                                        className="text-sm text-body transition-colors hover:text-heading"
                                    >
                                        {item.label}
                                    </a>
                                ))}
                            <Link to="/login" className="text-sm text-body transition-colors hover:text-heading">
                                Sign In
                            </Link>
                            <Link to="/register" className="text-sm text-body transition-colors hover:text-heading">
                                Get Started
                            </Link>
                        </div>
                    </nav>
                </div>

                <div className="flex flex-col gap-2 border-t border-border py-5 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-xs text-muted">© 2026 SPMS. All rights reserved.</p>
                    <p className="text-xs text-muted">
                        Forecasts and estimates are clearly labeled and are never presented as
                        measurements.
                    </p>
                </div>
            </div>
        </footer>
    )
}
