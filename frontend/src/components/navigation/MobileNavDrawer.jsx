import * as DialogPrimitive from '@radix-ui/react-dialog'
import { NavLink } from 'react-router'
import { X } from 'lucide-react'
import { BrandMark } from './BrandMark'
import { NAV_GROUPS } from '../../app/navigation'
import { cn } from '../../lib/utils'

/**
 * MobileNavDrawer (docs/04 §10 mobile) — the same centralized navigation
 * config presented as an accessible drawer (tablet/mobile). Built on the
 * Radix dialog primitive: Escape closes, overlay closes, focus is
 * trapped, and the trigger state is managed by the parent layout.
 */
export function MobileNavDrawer({ open, onOpenChange }) {
    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay
                    className={cn(
                        'fixed inset-0 z-50 bg-heading/50',
                        'data-[state=open]:animate-[spms-fade-in_200ms_ease-out]',
                        'motion-reduce:data-[state=open]:animate-none',
                    )}
                />
                <DialogPrimitive.Content
                    aria-describedby={undefined}
                    className={cn(
                        'fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] flex-col border-r border-border bg-surface shadow-2',
                        'focus:outline-none',
                        'data-[state=open]:animate-[spms-slide-in-left_250ms_ease-out]',
                        'motion-reduce:data-[state=open]:animate-none',
                    )}
                >
                    <DialogPrimitive.Title className="sr-only">Application navigation</DialogPrimitive.Title>

                    <div className="flex h-16 items-center justify-between border-b border-border px-4">
                        <span className="flex items-center gap-2.5">
                            <BrandMark variant="full" height={28} />
                        </span>
                        <DialogPrimitive.Close
                            aria-label="Close navigation"
                            className="inline-flex size-10 cursor-pointer items-center justify-center rounded-md text-body transition-colors duration-150 hover:bg-surface-alt hover:text-heading focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        >
                            <X className="size-5" aria-hidden="true" />
                        </DialogPrimitive.Close>
                    </div>

                    <nav aria-label="Application" className="scrollbar-subtle flex-1 overflow-y-auto px-3 py-4">
                        {NAV_GROUPS.map((group) => (
                            <div key={group.label} className="mb-5 last:mb-0">
                                <p className="px-2 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted">
                                    {group.label}
                                </p>
                                <ul className="space-y-0.5">
                                    {group.items.map((item) => {
                                        const Icon = item.icon
                                        return (
                                            <li key={item.path}>
                                                <NavLink
                                                    to={item.path}
                                                    onClick={() => onOpenChange(false)}
                                                    className={({ isActive }) =>
                                                        cn(
                                                            'flex min-h-11 items-center gap-3 rounded-md px-2.5 py-2 text-sm transition-colors duration-150',
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
                                                                className={cn('size-[18px] shrink-0', isActive && 'text-primary')}
                                                                aria-hidden="true"
                                                            />
                                                            <span>{item.name}</span>
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
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    )
}
