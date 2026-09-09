import { clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

/**
 * shadcn/ui-compatible class merger (F1 foundation).
 * Used by shared UI components introduced from F2 onward.
 */
export function cn(...inputs) {
    return twMerge(clsx(inputs))
}
