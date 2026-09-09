import { cva } from 'class-variance-authority'

/**
 * SPMS button variants (docs/12 §8).
 * Variants: primary | secondary | tertiary | destructive | icon
 * Shared separately so fast-refresh boundaries stay clean.
 */

export const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-semibold ' +
    'transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 ' +
    'focus-visible:outline-focus disabled:pointer-events-none disabled:opacity-50 ' +
    'cursor-pointer select-none',
    {
        variants: {
            variant: {
                primary:
                    'bg-primary text-primary-contrast shadow-1 hover:bg-primary-hover active:bg-primary-active',
                secondary:
                    'bg-surface text-heading border border-border hover:border-primary hover:text-primary',
                tertiary:
                    'bg-transparent text-body hover:bg-primary-soft hover:text-primary',
                destructive:
                    'bg-danger text-white hover:opacity-90',
                icon: 'h-10 w-10 p-0 bg-surface border border-border text-body hover:text-primary hover:border-primary',
            },
            size: {
                sm: 'h-8 px-3',
                md: 'h-10 px-4',
                lg: 'h-11 px-6 text-base',
                icon: 'h-10 w-10',
            },
        },
        defaultVariants: {
            variant: 'primary',
            size: 'md',
        },
    },
)
