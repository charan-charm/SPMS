import { forwardRef } from 'react'
import { cn } from '../../lib/utils'

/**
 * SPMS card primitive (docs/04 §5): border + subtle elevation,
 * radius 12px, restrained hover lift. Color is never the only signal.
 */

const Card = forwardRef(function Card({ className, interactive = false, ...props }, ref) {
    return (
        <div
            ref={ref}
            className={cn(
                'rounded-lg border border-border bg-surface p-6 shadow-1',
                'transition-colors duration-150',
                interactive &&
                    'cursor-pointer transition-[box-shadow,transform] duration-150 hover:shadow-2 hover:-translate-y-0.5',
                className,
            )}
            {...props}
        />
    )
})

const CardHeader = forwardRef(function CardHeader({ className, ...props }, ref) {
    return <div ref={ref} className={cn('mb-4', className)} {...props} />
})

const CardTitle = forwardRef(function CardTitle({ className, ...props }, ref) {
    return (
        <h3
            ref={ref}
            className={cn('font-display text-lg font-semibold text-heading', className)}
            {...props}
        />
    )
})

const CardDescription = forwardRef(function CardDescription({ className, ...props }, ref) {
    return <p ref={ref} className={cn('mt-1 text-sm text-muted', className)} {...props} />
})

const CardContent = forwardRef(function CardContent({ className, ...props }, ref) {
    return <div ref={ref} className={cn('text-sm text-body', className)} {...props} />
})

const CardFooter = forwardRef(function CardFooter({ className, ...props }, ref) {
    return <div ref={ref} className={cn('mt-4 flex items-center gap-3', className)} {...props} />
})

export { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter }
