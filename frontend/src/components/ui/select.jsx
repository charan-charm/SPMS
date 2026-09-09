import * as SelectPrimitive from '@radix-ui/react-select'
import { Check, ChevronDown } from 'lucide-react'
import { cn } from '../../lib/utils'

/** SPMS select primitive. */

const Select = SelectPrimitive.Root
const SelectValue = SelectPrimitive.Value

function SelectTrigger({ className, children, ...props }) {
    return (
        <SelectPrimitive.Trigger
            className={cn(
                'flex h-10 w-full items-center justify-between rounded-md border border-border bg-surface px-3 py-2',
                'text-sm text-body transition-colors duration-150 cursor-pointer',
                'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus',
                'disabled:cursor-not-allowed disabled:opacity-50',
                'data-[placeholder]:text-muted',
                className,
            )}
            {...props}
        >
            {children}
            <SelectPrimitive.Icon>
                <ChevronDown className="size-4 opacity-60" aria-hidden="true" />
            </SelectPrimitive.Icon>
        </SelectPrimitive.Trigger>
    )
}

function SelectContent({ className, children, position = 'popper', ...props }) {
    return (
        <SelectPrimitive.Portal>
            <SelectPrimitive.Content
                position={position}
                className={cn(
                    'relative z-50 max-h-72 min-w-[8rem] overflow-hidden rounded-md border border-border',
                    'bg-surface shadow-2',
                    className,
                )}
                {...props}
            >
                <SelectPrimitive.Viewport className="p-1">
                    {children}
                </SelectPrimitive.Viewport>
            </SelectPrimitive.Content>
        </SelectPrimitive.Portal>
    )
}

function SelectItem({ className, children, ...props }) {
    return (
        <SelectPrimitive.Item
            className={cn(
                'relative flex w-full cursor-pointer select-none items-center rounded-sm py-1.5 pl-8 pr-2',
                'text-sm text-body outline-none transition-colors duration-150',
                'focus:bg-surface-alt focus:text-heading data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
                className,
            )}
            {...props}
        >
            <span className="absolute left-2 flex size-4 items-center justify-center">
                <SelectPrimitive.ItemIndicator>
                    <Check className="size-4 text-primary" aria-hidden="true" />
                </SelectPrimitive.ItemIndicator>
            </span>
            <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
        </SelectPrimitive.Item>
    )
}

export { Select, SelectValue, SelectTrigger, SelectContent, SelectItem }
