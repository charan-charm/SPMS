import { forwardRef } from 'react'
import * as SwitchPrimitive from '@radix-ui/react-switch'
import { cn } from '../../lib/utils'

/** SPMS switch primitive — used for understandable binary preferences. */

const Switch = forwardRef(function Switch({ className, ...props }, ref) {
    return (
        <SwitchPrimitive.Root
            ref={ref}
            className={cn(
                'peer inline-flex h-5 w-9 shrink-0 items-center rounded-full border-2 border-transparent',
                'transition-colors duration-150 cursor-pointer',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
                'disabled:cursor-not-allowed disabled:opacity-50',
                'data-[state=checked]:bg-primary data-[state=unchecked]:bg-border',
                className,
            )}
            {...props}
        >
            <SwitchPrimitive.Thumb
                className={cn(
                    'pointer-events-none block size-4 rounded-full bg-white shadow-sm',
                    'transition-transform duration-150',
                    'data-[state=checked]:translate-x-4 data-[state=unchecked]:translate-x-0',
                )}
            />
        </SwitchPrimitive.Root>
    )
})

export { Switch }
