import { forwardRef } from 'react'
import * as SeparatorPrimitive from '@radix-ui/react-separator'
import { cn } from '../../lib/utils'

/** SPMS separator primitive. */

const Separator = forwardRef(function Separator(
    { className, orientation = 'horizontal', decorative = true, ...props },
    ref,
) {
    return (
        <SeparatorPrimitive.Root
            ref={ref}
            orientation={orientation}
            decorative={decorative}
            className={cn(
                'shrink-0 bg-border',
                orientation === 'horizontal' ? 'h-px w-full' : 'h-full w-px',
                className,
            )}
            {...props}
        />
    )
})

export { Separator }
