import * as DialogPrimitive from '@radix-ui/react-dialog'
import { X } from 'lucide-react'
import { cn } from '../../lib/utils'

/** SPMS dialog primitive — overlay + content, Escape/overlay close, focus trapped by Radix. */

const Dialog = DialogPrimitive.Root
const DialogTrigger = DialogPrimitive.Trigger
const DialogClose = DialogPrimitive.Close

function DialogContent({ className, children, ...props }) {
    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-heading/50 transition-opacity duration-200" />
            <DialogPrimitive.Content
                className={cn(
                    'fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2',
                    'rounded-lg border border-border bg-surface p-6 shadow-2',
                    'transition-all duration-200',
                    'focus:outline-none',
                    className,
                )}
                {...props}
            >
                {children}
                <DialogPrimitive.Close
                    className="absolute right-4 top-4 rounded-md p-1 text-muted transition-colors duration-150 hover:text-heading cursor-pointer focus-visible:outline-2 focus-visible:outline-focus"
                    aria-label="Close"
                >
                    <X className="size-4" aria-hidden="true" />
                </DialogPrimitive.Close>
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    )
}

function DialogHeader({ className, ...props }) {
    return <div className={cn('mb-4 pr-8', className)} {...props} />
}

function DialogTitle({ className, ...props }) {
    return (
        <DialogPrimitive.Title
            className={cn('font-display text-lg font-semibold text-heading', className)}
            {...props}
        />
    )
}

function DialogDescription({ className, ...props }) {
    return (
        <DialogPrimitive.Description
            className={cn('mt-1 text-sm text-muted', className)}
            {...props}
        />
    )
}

function DialogFooter({ className, ...props }) {
    return <div className={cn('mt-6 flex justify-end gap-3', className)} {...props} />
}

export {
    Dialog,
    DialogTrigger,
    DialogClose,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
}
