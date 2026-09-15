import { apiClient } from './client'

/**
 * SPMS Bills API module (B4 — docs/10 D-067/D-068/D-069 read surface).
 *
 * Thin endpoint definitions over the central API client: no business
 * logic, no fallback values, no derived statuses. Every response is
 * already normalized and household-scoped server-side; the frontend
 * displays exactly what arrives. bill_id values addressed here come
 * from the backend's own responses — the server still re-checks
 * ownership on every call.
 */

/** Extraction travels Mistral's OCR path (up to ~3 min per document). */
const PROCESS_TIMEOUT_MS = 300_000

/** A 25 MiB multipart part on a local link. */
const UPLOAD_TIMEOUT_MS = 60_000

/**
 * @param {AbortSignal} [signal]
 * @returns {Promise<{data: {bills: Array<object>}, meta: Object, requestId: string|null, status: number}>}
 */
export function listBills(signal = undefined) {
    return apiClient.get('/bills', { signal })
}

/**
 * @param {string} billId backend-issued id (never guessed, never cached across households)
 * @param {AbortSignal} [signal]
 */
export function getBill(billId, signal = undefined) {
    return apiClient.get(`/bills/${encodeURIComponent(billId)}`, { signal })
}

/**
 * B2 secure intake: exactly one multipart part named "file" — the
 * backend rejects every text field, so nothing else is ever appended.
 *
 * @param {File} file
 * @param {AbortSignal} [signal]
 * @returns {Promise<{data: {bill_id: string, status: string, document: {type: string, size_bytes: number}}}>}
 */
export function uploadBill(file, signal = undefined) {
    const form = new FormData()
    form.append('file', file)

    return apiClient.post('/bills/upload', form, { signal, timeoutMs: UPLOAD_TIMEOUT_MS })
}

/**
 * B3 application processing path — synchronous, guarded by the
 * backend's PROCESSING-only lifecycle gate (retried calls after
 * success are 409 INVALID_BILL_STATE, answered here as a plain
 * re-read by the caller, never a re-extraction).
 *
 * @param {string} billId
 * @param {AbortSignal} [signal]
 */
export function processBill(billId, signal = undefined) {
    return apiClient.post(`/bills/${encodeURIComponent(billId)}/process`, undefined, {
        signal,
        timeoutMs: PROCESS_TIMEOUT_MS,
    })
}
