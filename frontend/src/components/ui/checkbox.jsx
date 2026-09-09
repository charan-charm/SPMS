import { forwardRef } from 'react'
import * as CheckboxPrimitive from '@radix-ui/react-checkbox'
import { Check } from 'lucide-react'
import { cn } from '../../lib/utils'

/** SPMS checkbox primitive — keyboard operable, labeled via associated Label. */

const Checkbox = forwardRef(function Checkbox({ className, ...props }, ref) {
    return (
        <CheckboxPrimitive.Root
            ref={ref}
            className={cn(
                'peer size-4 shrink-0 rounded-sm border border-muted bg-surface',
                'transition-colors duration-150 cursor-pointer',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
                'disabled:cursor-not-allowed disabled:opacity-50',
                'data-[state=checked]:border-primary data-[state=checked]:bg-primary',
                className,
            )}
            {...props}
        >
            <CheckboxPrimitive.Indicator className="grid place-items-center text-primary-contrast">
                <Check className="size-3" aria-hidden="true" />
            </CheckboxPrimitive.Indicator>
        </CheckboxPrimitive.Root>
    )
})

export { Checkbox }
