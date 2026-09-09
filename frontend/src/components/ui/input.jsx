import { forwardRef } from 'react'
import { cn } from '../../lib/utils'

/**
 * SPMS input primitive (docs/12 §17 form system).
 * Server validation remains authoritative; this is presentation only.
 */

const Input = forwardRef(function Input({ className, type = 'text', invalid = false, ...props }, ref) {
    return (
        <input
            ref={ref}
            type={type}
            aria-invalid={invalid || undefined}
            className={cn(
                'flex h-10 w-full rounded-md border bg-surface px-3 py-2 text-sm text-body',
                'placeholder:text-muted transition-colors duration-150',
                'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus',
                'disabled:cursor-not-allowed disabled:opacity-50',
                invalid ? 'border-danger' : 'border-border',
                className,
            )}
            {...props}
        />
    )
})

export { Input }
