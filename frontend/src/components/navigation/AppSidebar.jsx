import { NavLink } from 'react-router'
import { NAV_GROUPS } from '../../app/navigation'
import { cn } from '../../lib/utils'

/**
 * AppSidebar (docs/04 §10) — persistent desktop navigation, permanently
 * expanded (F3 refinement 2.5: the collapse feature is removed by
 * owner decision).
 *
 * The sidebar is dedicated to navigation — no branding block (the
 * header owns SPMS branding). Navigation is vertically compact
 * (≈32px rows, tight group rhythm) so the rail reads organized rather
 * than stretched; labels are always visible so no navigation tooltips
 * are needed.
 *
 * Generated entirely from the centralized navigation config; grouped
 * Main / Energy / Intelligence / Output / System. The active route is
 * obvious through background, text weight, icon treatment and
 * aria-current — never color alone.
 */
export function AppSidebar() {
    return (
        <aside className="flex h-full w-64 flex-col border-r border-border bg-surface">
            <nav
                aria-label="Application"
                className="scrollbar-subtle flex-1 overflow-y-auto px-3 py-3"
            >
                {NAV_GROUPS.map((group) => (
                    <div key={group.label} className="mb-4 last:mb-0">
                        <p className="px-2 pb-1 text-[11px] font-semibold uppercase tracking-wider text-muted">
                            {group.label}
                        </p>
                        <ul className="space-y-0.5">
                            {group.items.map((item) => {
                                const Icon = item.icon
                                return (
                                    <li key={item.path}>
                                        <NavLink
                                            to={item.path}
                                            className={({ isActive }) =>
                                                cn(
                                                    'flex items-center gap-3 rounded-md px-2.5 py-1.5 text-sm transition-colors duration-150',
                                                    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
                                                    isActive
                                                        ? 'bg-primary-soft font-semibold text-primary-active'
                                                        : 'text-body hover:bg-surface-alt hover:text-heading',
                                                )
                                            }
                                        >
                                            {({ isActive }) => (
                                                <>
                                                    <Icon
                                                        className={cn(
                                                            'size-[18px] shrink-0',
                                                            isActive && 'text-primary',
                                                        )}
                                                        aria-hidden="true"
                                                    />
                                                    <span className="truncate">{item.name}</span>
                                                    {isActive && (
                                                        <span className="sr-only">(current page)</span>
                                                    )}
                                                </>
                                            )}
                                        </NavLink>
                                    </li>
                                )
                            })}
                        </ul>
                    </div>
                ))}
            </nav>
        </aside>
    )
}
