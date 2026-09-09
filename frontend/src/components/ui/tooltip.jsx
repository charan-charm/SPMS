import * as TooltipPrimitive from '@radix-ui/react-tooltip'
import { cn } from '../../lib/utils'

/** SPMS tooltip primitive — short clarification only, never a substitute for visible labels. */

const TooltipProvider = TooltipPrimitive.Provider
const Tooltip = TooltipPrimitive.Root
const TooltipTrigger = TooltipPrimitive.Trigger

function TooltipContent({ className, sideOffset = 6, ...props }) {
    return (
        <TooltipPrimitive.Portal>
            <TooltipPrimitive.Content
                sideOffset={sideOffset}
                className={cn(
                    'z-50 max-w-xs rounded-md border border-border bg-surface px-3 py-1.5',
                    'text-xs text-body shadow-2',
                    className,
                )}
                {...props}
            />
        </TooltipPrimitive.Portal>
    )
}

export { TooltipProvider, Tooltip, TooltipTrigger, TooltipContent }
