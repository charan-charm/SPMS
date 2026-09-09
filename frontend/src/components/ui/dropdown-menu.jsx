import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu'
import { cn } from '../../lib/utils'

/** SPMS dropdown-menu primitive. */

const DropdownMenu = DropdownMenuPrimitive.Root
const DropdownMenuTrigger = DropdownMenuPrimitive.Trigger

function DropdownMenuContent({ className, sideOffset = 4, ...props }) {
    return (
        <DropdownMenuPrimitive.Portal>
            <DropdownMenuPrimitive.Content
                sideOffset={sideOffset}
                className={cn(
                    'z-50 min-w-[10rem] rounded-md border border-border bg-surface p-1 shadow-2',
                    'transition-all duration-150',
                    className,
                )}
                {...props}
            />
        </DropdownMenuPrimitive.Portal>
    )
}

function DropdownMenuItem({ className, ...props }) {
    return (
        <DropdownMenuPrimitive.Item
            className={cn(
                'relative flex cursor-pointer select-none items-center rounded-sm px-2 py-1.5 text-sm text-body',
                'outline-none transition-colors duration-150',
                'focus:bg-surface-alt focus:text-heading data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
                className,
            )}
            {...props}
        />
    )
}

function DropdownMenuSeparator({ className, ...props }) {
    return (
        <DropdownMenuPrimitive.Separator
            className={cn('-mx-1 my-1 h-px bg-border', className)}
            {...props}
        />
    )
}

export {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
}
