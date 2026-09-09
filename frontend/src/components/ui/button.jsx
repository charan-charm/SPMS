import { forwardRef } from 'react'
import { Slot } from '@radix-ui/react-slot'
import { LoaderCircle } from 'lucide-react'
import { cn } from '../../lib/utils'
import { buttonVariants } from './button-variants'

/**
 * SPMS button primitive (docs/12 §8).
 * States: default, hover, active, loading, disabled, focus.
 * Loading preserves dimensions (docs/12 §8).
 */

export const Button = forwardRef(function Button(
    { className, variant, size, asChild = false, loading = false, disabled, children, ...props },
    ref,
) {
    const Comp = asChild ? Slot : 'button'

    return (
        <Comp
            ref={ref}
            className={cn(buttonVariants({ variant, size }), className)}
            disabled={asChild ? undefined : disabled || loading}
            aria-busy={loading || undefined}
            {...props}
        >
            {asChild ? (
                children
            ) : (
                <>
                    {loading && <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />}
                    {children}
                </>
            )}
        </Comp>
    )
})
