import { useId, useState } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import { ChevronDown } from 'lucide-react'
import { DURATION } from '../../lib/motion'
import { cn } from '../../lib/utils'

/**
 * Disclosure — progressive disclosure for technical detail (B4 §4:
 * extraction internals exist but never dominate the initial view).
 * Keyboard-operable button header with the full ARIA pattern; the
 * 250ms expand/collapse matches the docs/12 §13 expand token and the
 * root MotionConfig reducedMotion="user" policy collapses it instantly.
 */
export function Disclosure({ label, children, className, defaultOpen = false }) {
    const [open, setOpen] = useState(defaultOpen)
    const panelId = useId()

    return (
        <div className={cn('border-t border-border pt-3', className)}>
            <button
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((value) => !value)}
                className="inline-flex items-center gap-1.5 rounded-md text-sm font-medium text-secondary hover:text-secondary-active focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                {label}
                <ChevronDown
                    className={cn('size-4 transition-transform duration-150', open && 'rotate-180')}
                    aria-hidden="true"
                />
            </button>
            <AnimatePresence initial={false}>
                {open && (
                    <motion.div
                        key="disclosure-panel"
                        id={panelId}
                        initial={{ height: 0, opacity: 0 }}
                        animate={{ height: 'auto', opacity: 1 }}
                        exit={{ height: 0, opacity: 0 }}
                        transition={{ duration: DURATION.expand / 1000, ease: 'easeInOut' }}
                        style={{ overflow: 'hidden' }}
                    >
                        <div className="pt-3">{children}</div>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    )
}
