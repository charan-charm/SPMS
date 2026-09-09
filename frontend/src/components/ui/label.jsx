import { forwardRef } from 'react'
import * as LabelPrimitive from '@radix-ui/react-label'
import { cn } from '../../lib/utils'

/** SPMS label primitive — always associates with its control via htmlFor. */

const Label = forwardRef(function Label({ className, ...props }, ref) {
    return (
        <LabelPrimitive.Root
            ref={ref}
            className={cn(
                'text-sm font-medium text-heading select-none',
                'peer-disabled:cursor-not-allowed peer-disabled:opacity-50',
                className,
            )}
            {...props}
        />
    )
})

export { Label }
