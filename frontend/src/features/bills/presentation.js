import {
    CircleHelp,
    FileQuestion,
    ImageOff,
    PencilLine,
    ScanText,
    TriangleAlert,
    CircleCheck,
    CircleDashed,
    FileSpreadsheet,
    FileText,
    Image as ImageIcon,
} from 'lucide-react'
import { formatMoney } from '../../lib/money'

/**
 * Bills workspace presentation metadata (B4).
 *
 * Pure translation layer between the backend's authoritative vocabulary
 * (docs/10 D-067/D-069) and understandable bill language. Nothing here
 * derives, corrects or completes data: every value rendered by the
 * workspace arrives from the API, and a value the API never sent stays
 * honestly absent (NOT_AVAILABLE), never 0, "Unknown" or "Estimated".
 * Color never carries meaning alone — every label below renders as text.
 */

export const NOT_AVAILABLE = 'Not available'

/** Bill lifecycle labels (B1 BillStatus vocabulary — no state invented). */
export const BILL_STATUS = {
    UPLOADED: { label: 'Uploaded', tone: 'muted' },
    PROCESSING: { label: 'Processing', tone: 'info' },
    EXTRACTED: { label: 'Extracted', tone: 'info' },
    NEEDS_REVIEW: { label: 'Review needed', tone: 'warning' },
    VERIFIED: { label: 'Verified', tone: 'success' },
    NOT_A_BILL: { label: 'Not a bill', tone: 'muted' },
    UNREADABLE: { label: 'Unreadable', tone: 'muted' },
    UNCERTAIN: { label: 'Uncertain', tone: 'muted' },
    PROCESSING_FAILED: { label: 'Processing failed', tone: 'danger' },
}

/** Honest outcomes for documents that produced no usable bill. */
export const TERMINAL_OUTCOME = {
    NOT_A_BILL: {
        icon: FileQuestion,
        title: 'Document not recognized as an electricity bill',
        body: "SPMS couldn't identify the information needed for household energy analysis.",
    },
    UNREADABLE: {
        icon: ImageOff,
        title: "SPMS couldn't read this bill",
        body: 'Try uploading a clearer image or a higher-quality PDF.',
    },
    UNCERTAIN: {
        icon: CircleHelp,
        title: "SPMS couldn't confidently identify this document",
        body: 'Please upload a clearer electricity bill.',
    },
    PROCESSING_FAILED: {
        icon: TriangleAlert,
        title: "We couldn't finish analyzing this bill",
        body: 'The analysis stopped before it could read your bill. Please upload the bill again.',
    },
}

/**
 * Validation rules (B1) translated; the persisted values stay verbatim.
 * `short` labels exist for the compact per-check validation list; the
 * full label explains a conflict. Status itself always comes from the
 * backend — this table only translates it (B4 refinement §2: React never
 * recalculates a check).
 */
export const VALIDATION_RULE = {
    METER_CONSUMPTION: {
        label: 'Meter readings vs consumption',
        short: 'Meter readings',
        field: 'consumption_kwh',
        pass: 'Meter readings match the consumption',
        conflict: 'Meter readings and consumption do not match',
        notChecked: 'Could not be checked — some readings are missing',
    },
    SOLAR_PRODUCTION: {
        label: 'Solar readings vs solar units',
        short: 'Solar readings',
        field: 'solar_units_kwh',
        pass: 'Solar readings match the solar units',
        conflict: 'Solar readings and solar units do not match',
        notChecked: 'Could not be checked — some readings are missing',
    },
    NET_UNITS: {
        label: 'Net units from consumption and solar',
        short: 'Net units',
        field: 'net_units_kwh',
        pass: 'Net units match consumption minus solar',
        conflict: 'Net units do not match the other readings',
        notChecked: 'Could not be checked — some readings are missing',
    },
}

/**
 * The three validation states (B1) as plain result words. PASS means
 * "consistent with the other readings" — never "verified" (refinement
 * §5/§9: no fourth state is invented).
 */
export const VALIDATION_RESULT_TEXT = {
    PASS: 'Consistent',
    CONFLICT: 'Needs review',
    NOT_CHECKED: 'Not checked',
}

export const VALIDATION_STATUS_ICON = {
    PASS: CircleCheck,
    CONFLICT: TriangleAlert,
    NOT_CHECKED: CircleDashed,
}

/** Quality codes (B1 QualityCode) in understandable language. */
export const QUALITY_MESSAGE = {
    MISSING_CONSUMPTION: 'Consumption is missing from this bill',
    MISSING_BILLING_PERIOD: 'The billing period is missing',
    MISSING_METER_READINGS: 'Meter readings are missing',
    MISSING_SOLAR_READINGS: 'Solar readings are missing',
    UNREADABLE_FIELD: 'Some values were hard to read',
    AMBIGUOUS_AMOUNT: 'Some amounts were ambiguous on the bill',
    CONFLICTING_METER_READINGS: 'The meter readings appear inconsistent',
    INCONSISTENT_NET_UNITS: 'Net units do not match the other readings',
    INCOMPLETE_HISTORY: 'Gap in verified billing history acknowledged',
    BILLING_GAP_PRECEDING: 'An unbilled period precedes this statement',
    // D-071 ownership/evidence codes — translated words, never raw codes.
    NO_BILL_EVIDENCE: 'SPMS could not find bill information it could use in this document',
    SERVICE_NUMBER_MISMATCH: 'Service number does not match this household',
    SERVICE_NUMBER_MISSING: 'Service number could not be confirmed from this bill',
    HOUSEHOLD_SERVICE_NOT_CONFIGURED: 'Your household has no registered service number yet, so ownership cannot be confirmed',
}

/** Bill provenance/creation source. */
export const BILL_SOURCE = {
    APSPDCL_EXPORT: { label: 'APSPDCL Statement', tone: 'info' },
    ELECTRICITY_BILL: { label: 'Electricity Bill', tone: 'neutral' },
    USER_MANUAL: { label: 'Manual Entry', tone: 'muted' },
    GOODWE_SEMS: { label: 'GoodWe SEMS', tone: 'secondary' },
}

/** Chronological billing period sequence statuses. */
export const SEQUENCE_STATUS = {
    FIRST_BILL: {
        label: 'First bill',
        tone: 'neutral',
        title: 'First bill in sequence',
        message: 'This is the initial bill recorded for your household.',
    },
    CONTIGUOUS: {
        label: 'Continuous period',
        tone: 'positive',
        title: 'Continuous billing period',
        message: 'This bill continues seamlessly from your previous verified bill.',
    },
    GAP_DETECTED: {
        label: 'Billing gap detected',
        tone: 'warning',
        title: 'Gap in billing history',
        message: 'There is an unbilled period between your previous verified bill and this statement.',
    },
    DUPLICATE_PERIOD: {
        label: 'Duplicate period',
        tone: 'danger',
        title: 'Duplicate billing period',
        message: 'A verified bill already covers this exact billing period.',
    },
    OVERLAPPING_PERIOD: {
        label: 'Overlapping period',
        tone: 'danger',
        title: 'Overlapping billing period',
        message: 'This bill overlaps with an already verified billing period.',
    },
    INVALID_REVERSED: {
        label: 'Reversed dates',
        tone: 'danger',
        title: 'Invalid billing period',
        message: 'The billing period start date is after the end date.',
    },
}

/**
 * Ownership outcomes (D-071 Ownership vocabulary) as plain text. This
 * answers only "does the bill demonstrably belong to this household's
 * registered service?" — separate from document recognition and from
 * value validation, and never a claim of verification.
 */
export const OWNERSHIP_STATUS = {
    MATCH: {
        label: 'Service number matches this household',
        tone: 'positive',
        icon: CircleCheck,
    },
    MISMATCH: {
        label: "Service number doesn't match this household",
        tone: 'warning',
        icon: TriangleAlert,
    },
    NOT_ON_BILL: {
        label: 'Service number could not be confirmed',
        tone: 'neutral',
        icon: CircleDashed,
    },
    HOUSEHOLD_NOT_CONFIGURED: {
        label: 'Add your service number to your household to confirm ownership',
        tone: 'neutral',
        icon: CircleHelp,
    },
}

/** Provenance sources (B1 ProvenanceSource) — never implied, only shown. */
export const PROVENANCE_SOURCE = {
    AI_EXTRACTION: { label: 'AI extracted', icon: ScanText },
    USER_CORRECTION: { label: 'Corrected by you', icon: PencilLine },
    STRUCTURED_IMPORT: { label: 'APSPDCL structured import', icon: FileSpreadsheet },
}

/** Source-aware bill types distinguishing PDF, Image, and Structured XLS. */
export const DOCUMENT_SOURCE_TYPE = {
    STRUCTURED_XLS: {
        id: 'STRUCTURED_XLS',
        label: 'APSPDCL Excel (.xls)',
        badge: 'APSPDCL Excel (.xls)',
        method: 'Structured import',
        processing: 'Structured import',
        methodTone: 'neutral',
        icon: FileSpreadsheet,
        isAiExtracted: false,
        supportsConfidence: false,
        description: 'Imported directly from the APSPDCL spreadsheet. No OCR used.',
    },
    PDF: {
        id: 'PDF',
        label: 'Electricity bill PDF',
        badge: 'Electricity bill PDF',
        method: 'PDF extraction / OCR where applicable',
        processing: 'PDF extraction / OCR where applicable',
        methodTone: 'info',
        icon: FileText,
        isAiExtracted: true,
        supportsConfidence: true,
        description: 'PDF extraction / OCR where applicable',
    },
    IMAGE: {
        id: 'IMAGE',
        label: 'Electricity bill image',
        badge: 'Electricity bill image',
        method: 'OCR extraction',
        processing: 'OCR extraction',
        methodTone: 'info',
        icon: ImageIcon,
        isAiExtracted: true,
        supportsConfidence: true,
        description: 'OCR extraction',
    },
}

/**
 * Derives the authoritative document source type for a bill.
 */
export function getBillDocumentSource(bill) {
    if (!bill) {
        return DOCUMENT_SOURCE_TYPE.PDF
    }

    if (bill.document_type && DOCUMENT_SOURCE_TYPE[bill.document_type]) {
        return DOCUMENT_SOURCE_TYPE[bill.document_type]
    }

    const source = bill.source ?? ''
    const hasStatementSerial = bill.source_statement_serial !== undefined && bill.source_statement_serial !== null
    const hasStructuredProv = Object.values(bill.provenance ?? {}).some(
        (p) => p?.source === 'STRUCTURED_IMPORT'
    )
    const isApspdcl =
        source === 'APSPDCL_EXPORT' ||
        hasStatementSerial ||
        hasStructuredProv ||
        bill.extraction?.assessment_reason?.toLowerCase().includes('apspdcl')

    if (isApspdcl) {
        return DOCUMENT_SOURCE_TYPE.STRUCTURED_XLS
    }

    const mime = (bill.mime_type ?? '').toLowerCase()
    if (mime.includes('image') || mime.includes('jpeg') || mime.includes('jpg') || mime.includes('png')) {
        return DOCUMENT_SOURCE_TYPE.IMAGE
    }
    if (mime.includes('pdf')) {
        return DOCUMENT_SOURCE_TYPE.PDF
    }

    return DOCUMENT_SOURCE_TYPE.PDF
}

/** Solar applicability across historical billing periods. */
export const SOLAR_APPLICABILITY = {
    BEFORE_SOLAR_INSTALLATION: {
        label: 'Solar not active / not recorded for this billing period',
        short: 'Pre-solar period',
        badge: 'Pre-solar',
        tone: 'neutral',
    },
    SOLAR_ACTIVE: {
        label: 'Active solar net metering',
        short: 'Solar active',
        badge: 'Solar active',
        tone: 'success',
    },
    SOLAR_DATA_MISSING: {
        label: 'Solar data missing for this period',
        short: 'Data missing',
        badge: 'Missing readings',
        tone: 'warning',
    },
    SOLAR_DATA_AVAILABLE: {
        label: 'Solar data available',
        short: 'Available',
        badge: 'Available',
        tone: 'success',
    },
    UNKNOWN: {
        label: 'Solar status unknown',
        short: 'Unknown',
        badge: 'Unknown',
        tone: 'neutral',
    },
}

/** Document assessments (B1 DocumentAssessment). */
export const ASSESSMENT_LABEL = {
    VALID: 'Document recognized',
    VALID_WITH_LIMITATIONS: 'Document recognized with limitations',
    NOT_A_BILL: 'Not an electricity bill',
    UNREADABLE: 'Document could not be read',
    UNCERTAIN: 'Document could not be identified confidently',
}

/** Field display metadata — labels and units only; the API owns values. */
export const FIELD_LABEL = {
    service_number: { label: 'Service number', kind: 'text' },
    source_statement_serial: { label: 'Statement serial', kind: 'text' },
    billing_period_start: { label: 'Billing period start', kind: 'date' },
    billing_period_end: { label: 'Billing period end', kind: 'date' },
    bill_date: { label: 'Bill date', kind: 'date' },
    due_date: { label: 'Due date', kind: 'date' },
    consumption_kwh: { label: 'Consumption', kind: 'energy', unit: 'kWh' },
    previous_meter_reading: { label: 'Previous meter reading', kind: 'energy', unit: 'kWh' },
    present_meter_reading: { label: 'Present meter reading', kind: 'energy', unit: 'kWh' },
    previous_solar_reading: { label: 'Previous solar reading', kind: 'energy', unit: 'kWh' },
    present_solar_reading: { label: 'Present solar reading', kind: 'energy', unit: 'kWh' },
    solar_units_kwh: { label: 'Solar units', kind: 'energy', unit: 'kWh' },
    net_units_kwh: { label: 'Net units', kind: 'energy', unit: 'kWh', signed: true },
    opening_kvah: { label: 'Opening kVAh', kind: 'energy', unit: 'kVAh' },
    closing_kvah: { label: 'Closing kVAh', kind: 'energy', unit: 'kVAh' },
    consumption_kvah: { label: 'Consumption kVAh', kind: 'energy', unit: 'kVAh' },
    bill_amount: { label: 'Bill amount', kind: 'money' },
    arrears: { label: 'Arrears', kind: 'money' },
    total_due: { label: 'Total due', kind: 'money' },
    energy_charges: { label: 'Energy charges', kind: 'money' },
    fixed_charges: { label: 'Fixed charges', kind: 'money' },
    customer_service_charges: { label: 'Customer service charges', kind: 'money' },
    other_charges: { label: 'Other charges', kind: 'money' },
    taxes_adjustments: { label: 'Taxes and adjustments', kind: 'money' },
    utility_provider: { label: 'Utility provider', kind: 'text' },
    tariff_category: { label: 'Tariff category', kind: 'text' },
    meter_type: { label: 'Meter type', kind: 'text' },
    contracted_load: { label: 'Contracted load', kind: 'energy', unit: 'kW' },
}

/**
 * Format a backend decimal string for display: thousands grouping and
 * trailing zeros trimmed — never rounding beyond the source precision,
 * never a sign flip, never a fabricated number. `signed` forces an
 * explicit + for positive values (net units), so +177 stays visibly +177.
 */
export function formatEnergy(raw, { signed = false } = {}) {
    if (raw === null || raw === undefined) {
        return null
    }
    const numeric = Number(raw)
    if (!Number.isFinite(numeric)) {
        return String(raw)
    }
    const formatted = numeric.toLocaleString(undefined, { maximumFractionDigits: 3 })
    if (signed && numeric > 0) {
        return `+${formatted}`
    }
    return formatted
}

/**
 * Masks electricity service number preserving only the final 4 digits.
 * Example: 8623310010998 -> ••••••••0998
 */
export function maskServiceNumber(serviceNumber) {
    if (!serviceNumber || typeof serviceNumber !== 'string') {
        return null
    }
    const clean = serviceNumber.trim()
    if (clean.length <= 4) {
        return clean
    }
    const last4 = clean.slice(-4)
    return `••••••••${last4}`
}

/**
 * Canonical billing history ordering:
 * billing_period_start DESC, billing_period_end DESC, id DESC.
 */
export function sortBillsChronologically(bills) {
    if (!Array.isArray(bills)) {
        return []
    }
    return [...bills].sort((a, b) => {
        const startA = a.billing_period_start || a.bill_date || ''
        const startB = b.billing_period_start || b.bill_date || ''
        if (startA !== startB) {
            if (!startA) return 1
            if (!startB) return -1
            return startB.localeCompare(startA)
        }
        const endA = a.billing_period_end || ''
        const endB = b.billing_period_end || ''
        if (endA !== endB) {
            if (!endA) return 1
            if (!endB) return -1
            return endB.localeCompare(endA)
        }
        const idA = a.bill_id || a.id || ''
        const idB = b.bill_id || b.id || ''
        return idB.localeCompare(idA)
    })
}

export function formatFieldValue(field, raw) {
    if (raw === null || raw === undefined || raw === '') {
        return null
    }
    if (field === 'service_number') {
        return maskServiceNumber(String(raw))
    }
    const meta = FIELD_LABEL[field]
    if (meta?.kind === 'money') {
        return formatMoney(raw)
    }
    if (meta?.kind === 'date') {
        return formatDate(raw)
    }
    if (meta?.kind === 'energy') {
        return formatEnergy(raw, { signed: Boolean(meta.signed) })
    }
    return String(raw)
}

/** Format a Y-m-d date (presentation only; unparseable stays visible). */
export function formatDate(raw) {
    if (raw === null || raw === undefined) {
        return null
    }
    const date = new Date(`${raw}T00:00:00Z`)
    if (Number.isNaN(date.getTime())) {
        return String(raw)
    }
    return date.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    })
}

export function formatPeriod(start, end) {
    const from = formatDate(start)
    const to = formatDate(end)
    if (from === null && to === null) {
        return null
    }
    if (from !== null && to !== null) {
        return `${from} - ${to}`
    }
    return from ?? to
}

/**
 * Formats a billing period into short Month Year (e.g. "Sep 2026").
 * Evaluates period end, period start, or bill date in order.
 */
export function formatBillingMonthYear(start, end, billDate = null) {
    const raw = end || start || billDate
    if (!raw) return NOT_AVAILABLE
    const date = new Date(`${raw}T00:00:00Z`)
    if (Number.isNaN(date.getTime())) return String(raw)
    return date.toLocaleDateString('en-US', {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    })
}

/**
 * Formats a billing period into full Month Year (e.g. "September 2026").
 */
export function formatBillingFullMonthYear(start, end, billDate = null) {
    const raw = end || start || billDate
    if (!raw) return NOT_AVAILABLE
    const date = new Date(`${raw}T00:00:00Z`)
    if (Number.isNaN(date.getTime())) return String(raw)
    return date.toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    })
}

/** Display-only byte size for locally selected files (never a backend value). */
export function formatBytes(bytes) {
    if (!Number.isFinite(bytes) || bytes < 0) {
        return null
    }
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toLocaleString(undefined, { maximumFractionDigits: 1 })} MB`
    }
    if (bytes >= 1024) {
        return `${Math.round(bytes / 1024)} KB`
    }
    return `${bytes} B`
}

/**
 * Confidence level from a backend 0-1 score (docs/12 10 presentation
 * bands — a display mapping, never a claim about the data itself; the
 * underlying score is always shown alongside so nothing is hidden).
 */
export function confidenceLevelFromScore(score) {
    const numeric = Number(score)
    if (!Number.isFinite(numeric)) {
        return null
    }
    if (numeric >= 0.9) return 'HIGH'
    if (numeric >= 0.75) return 'MODERATE'
    if (numeric >= 0.5) return 'LOW'
    if (numeric > 0) return 'VERY_LOW'
    return 'INSUFFICIENT'
}

/**
 * Client-side EARLY checks for the upload panel (B4 §18). These exist
 * purely to save the user an obvious round trip; the server-side B2
 * intake (real size, magic signatures, one-part multipart) remains the
 * sole authority and re-checks everything.
 */
export const MAX_UPLOAD_BYTES = 25 * 1024 * 1024
export const ACCEPTED_UPLOAD_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'xls']
export const ACCEPTED_UPLOAD_ATTR = '.jpg,.jpeg,.png,.pdf,.xls,image/jpeg,image/png,application/pdf,application/vnd.ms-excel'

export function clientSideUploadCheck(file) {
    const extension = (file.name.split('.').pop() ?? '').toLowerCase()
    if (!ACCEPTED_UPLOAD_EXTENSIONS.includes(extension)) {
        return 'SPMS can read electricity bills as PDF, JPG, PNG or APSPDCL Excel (.xls) files. Please choose one of those.'
    }
    if (file.size > MAX_UPLOAD_BYTES) {
        return `This file is larger than 25 MB (${formatBytes(file.size)}). Please choose a smaller file.`
    }
    if (file.size === 0) {
        return 'This file appears to be empty. Please choose a different copy of your bill.'
    }
    return null
}
