import * as TabsPrimitive from '@radix-ui/react-tabs'
import { cn } from '../../lib/utils'

/** SPMS tabs primitive — accent underline style without heavy fill (docs/04 §6). */

function Tabs({ className, ...props }) {
    return <TabsPrimitive.Root className={cn('w-full', className)} {...props} />
}

function TabsList({ className, ...props }) {
    return (
        <TabsPrimitive.List
            className={cn('inline-flex items-center gap-1 border-b border-border', className)}
            {...props}
        />
    )
}

function TabsTrigger({ className, ...props }) {
    return (
        <TabsPrimitive.Trigger
            className={cn(
                'inline-flex items-center px-3 py-2 text-sm font-medium text-muted cursor-pointer',
                'border-b-2 border-transparent -mb-px transition-colors duration-150',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
                'disabled:pointer-events-none disabled:opacity-50',
                'data-[state=active]:border-primary data-[state=active]:text-primary hover:text-heading',
                className,
            )}
            {...props}
        />
    )
}

function TabsContent({ className, ...props }) {
    return (
        <TabsPrimitive.Content
            className={cn('pt-4 focus-visible:outline-none', className)}
            {...props}
        />
    )
}

export { Tabs, TabsList, TabsTrigger, TabsContent }
