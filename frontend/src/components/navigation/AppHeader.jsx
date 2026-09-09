import { NavLink, useLocation, Link } from 'react-router'
import { Bell, CircleDashed, Menu } from 'lucide-react'
import { BrandMark } from './BrandMark'
import { ThemeSwitch } from './ThemeSwitch'
import { findNavItem } from '../../app/navigation'
import { Button } from '../ui/button'
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '../ui/dropdown-menu'

/**
 * AppHeader (docs/04 §11) — answers "where am I, what is happening?"
 *
 * The header owns SPMS branding: a stable left-side brand area showing
 * the approved full logo, which is theme-aware — logo-light.png in the
 * light theme, logo-dark.png in the dark theme (no dark compact asset
 * exists, so the emblem-only compact asset is not used here). The
 * wordmark is part of the logo image; the header height, divider and
 * page title placement are unchanged.
 *
 * The connection-status slot is intentionally neutral (no invented
 * GoodWe/system status until backend integration), and the
 * notification/account entries render structure with explicit
 * "not available yet" semantics — no fabricated counts or names.
 */
export function AppHeader({ onOpenNavigation }) {
    const location = useLocation()
    const current = findNavItem(location.pathname)

    return (
        <header className="sticky top-0 z-40 border-b border-border bg-background/90 backdrop-blur-sm">
            <div className="flex h-16 items-center gap-3 px-4 sm:px-6">
                {/* Mobile navigation trigger */}
                <Button
                    variant="icon"
                    className="lg:hidden"
                    onClick={onOpenNavigation}
                    aria-label="Open navigation menu"
                >
                    <Menu className="size-5" aria-hidden="true" />
                </Button>

                {/* Brand — stable left-side branding area; theme-aware
                    full logo (light/dark asset switches with the theme) */}
                <Link
                    to="/dashboard"
                    aria-label="SPMS home"
                    className="hidden items-center sm:inline-flex"
                >
                    <BrandMark variant="full" height={32} />
                </Link>

                <span
                    aria-hidden="true"
                    className="hidden h-6 w-px bg-border sm:block"
                />

                {/* Page / context information (route-derived, no fake data) */}
                <div className="min-w-0">
                    <p className="truncate font-display text-base font-semibold text-heading">
                        {current ? current.name : 'SPMS'}
                    </p>
                </div>

                <div className="ml-auto flex items-center gap-2">
                    {/* Connection / service status slot — neutral placeholder
                        until real backend status exists (never fabricated). */}
                    <span
                        className="hidden items-center gap-1.5 rounded-full border border-border bg-surface px-2.5 py-1 text-xs text-muted md:inline-flex"
                        title="Connection status will appear here once backend integration is available"
                    >
                        <CircleDashed className="size-3.5" aria-hidden="true" />
                        Status: not connected yet
                    </span>

                    {/* Notification entry — structural only, no unread counts. */}
                    <NavLink
                        to="/notifications"
                        aria-label="Notifications (no unread indicator until backend integration)"
                        className="inline-flex size-10 items-center justify-center rounded-md border border-border bg-surface text-body transition-colors duration-150 hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    >
                        <Bell className="size-4" aria-hidden="true" />
                    </NavLink>

                    <ThemeSwitch />

                    {/* Account entry — structural only, no fabricated identity. */}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                aria-label="Account menu (account features not available yet)"
                                className="inline-flex size-10 cursor-pointer items-center justify-center rounded-md border border-border bg-surface text-body transition-colors duration-150 hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                            >
                                <span
                                    aria-hidden="true"
                                    className="grid size-7 place-items-center rounded-full bg-primary-soft text-xs font-bold text-primary-active"
                                >
                                    ?
                                </span>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem disabled>Account details (not available yet)</DropdownMenuItem>
                            <DropdownMenuItem disabled>Sign out (not available yet)</DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem disabled className="text-muted">
                                Authentication arrives in a later milestone
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </header>
    )
}
