import { useState, useRef, useEffect } from 'react'
import { Check } from 'lucide-react'
import { Button } from '../../components/ui/button'
import { cn } from '../../lib/utils'

/**
 * Validate numeric, text, or date value based on field semantics.
 */
function validateFieldValue(field, kind, val) {
    if (val === '' || val === null || val === undefined) {
        return 'Value cannot be empty'
    }

    if (kind === 'energy' || kind === 'money' || kind === 'load') {
        const num = Number(val)
        if (!Number.isFinite(num)) {
            return 'Please enter a valid number'
        }

        if (field === 'net_units_kwh') {
            if (num < -50000 || num > 50000) {
                return 'Net units must be between -50,000 and 50,000 kWh'
            }
        } else if (kind === 'energy' || kind === 'load') {
            if (num < 0) {
                return 'Energy readings cannot be negative'
            }
            if (num > 100000) {
                return 'Value exceeds maximum expected energy (100,000 kWh)'
            }
        } else if (kind === 'money') {
            if (field !== 'arrears' && num < 0) {
                return 'Amount cannot be negative'
            }
            if (num > 1000000) {
                return 'Amount exceeds maximum expected limit (₹1,000,000)'
            }
        }
    }

    if (kind === 'date') {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(val)) {
            return 'Date must follow YYYY-MM-DD format'
        }
    }

    return null
}

/**
 * InlineFieldEditor — interactive inline editing control for bill values.
 *
 * Implements:
 * - Clear focus on mount
 * - Strict numeric & format sanity checks
 * - Units display
 * - Transparent display of original AI extracted value
 * - Cancel and Save correction controls
 * - Loading, success, and error feedback states
 */
export function InlineFieldEditor({
    field,
    label,
    initialValue,
    rawAiValue = null,
    unit = null,
    kind = 'energy',
    onSave,
    onCancel,
    isCardMode = false,
}) {
    const [draft, setDraft] = useState(
        initialValue !== null && initialValue !== undefined ? String(initialValue) : ''
    )
    const [error, setError] = useState(null)
    const [saving, setSaving] = useState(false)
    const [success, setSuccess] = useState(false)
    const inputRef = useRef(null)

    useEffect(() => {
        inputRef.current?.focus()
    }, [])

    const handleSubmit = async (e) => {
        e.preventDefault()
        const trimmed = draft.trim()
        const err = validateFieldValue(field, kind, trimmed)
        if (err) {
            setError(err)
            return
        }

        setError(null)
        setSaving(true)
        try {
            await onSave(field, trimmed)
            setSuccess(true)
            setTimeout(() => {
                onCancel()
            }, 350)
        } catch (submitErr) {
            setError(submitErr?.message || 'Failed to save correction. Please check the value and try again.')
        } finally {
            setSaving(false)
        }
    }

    return (
        <form
            onSubmit={handleSubmit}
            className={cn(
                'flex flex-col gap-2 rounded-lg border border-primary/40 bg-surface shadow-xs',
                isCardMode ? 'p-3 mt-2' : 'p-2.5 my-1.5 w-full'
            )}
            role="form"
            aria-label={`Edit ${label}`}
        >
            <div className="flex items-center justify-between gap-2">
                <label htmlFor={`input-${field}`} className="text-xs font-semibold text-heading">
                    Edit {label}
                </label>
                {unit && <span className="text-xs font-medium text-muted">{unit}</span>}
            </div>

            <div className="flex items-center gap-2">
                <div className="relative flex-1">
                    <input
                        ref={inputRef}
                        id={`input-${field}`}
                        type={kind === 'date' ? 'date' : 'text'}
                        inputMode={kind === 'date' ? undefined : (kind === 'text' ? 'text' : 'decimal')}
                        value={draft}
                        onChange={(e) => {
                            setDraft(e.target.value)
                            if (error) setError(null)
                        }}
                        disabled={saving}
                        className={cn(
                            'w-full rounded border border-border bg-surface px-2.5 py-1.5 font-mono text-heading focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary',
                            isCardMode ? 'text-lg font-bold' : 'text-sm font-semibold'
                        )}
                        placeholder={rawAiValue ?? (kind === 'date' ? 'YYYY-MM-DD' : '0.000')}
                    />
                </div>
            </div>

            {/* AI extracted original reference for audit transparency */}
            {rawAiValue !== null && rawAiValue !== undefined && (
                <p className="text-xs text-muted">
                    AI extracted: <span className="font-mono font-medium text-body">{rawAiValue} {unit ?? ''}</span>
                </p>
            )}

            {error && (
                <p className="text-xs font-medium text-danger" role="alert">
                    {error}
                </p>
            )}

            {success && (
                <p className="flex items-center gap-1 text-xs font-semibold text-primary">
                    <Check className="size-3.5" aria-hidden="true" /> Correction saved
                </p>
            )}

            <div className="flex items-center gap-2 pt-1">
                <Button
                    type="submit"
                    size="sm"
                    loading={saving}
                    disabled={saving}
                >
                    Save correction
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    onClick={onCancel}
                    disabled={saving}
                >
                    Cancel
                </Button>
            </div>
        </form>
    )
}
