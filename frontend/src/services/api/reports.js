import { buildApiUrl } from './config'
import { apiClient, ApiError } from './client'

/**
 * SPMS Reporting & Data Export API (F8 — docs/02 §2 F8, docs/03 §4).
 */

/**
 * Fetch reports metadata summary for the active household (counts & date ranges).
 *
 * @param {AbortSignal} [signal]
 * @returns {Promise<{reports: {bills: {count: number, first_date: string|null, last_date: string|null}, solar: {count: number, first_date: string|null, last_date: string|null}}}>}
 */
export async function getReportsSummary(signal) {
    const { data } = await apiClient.get('/reports/summary', { signal })
    return data
}

/**
 * Triggers a secure, programmatic CSV download for a specified reports endpoint.
 * Preserves HttpOnly session credentials and parses RFC 4180 Content-Disposition filename.
 *
 * @param {string} endpoint - Relative path (e.g. '/reports/bills/export')
 * @param {string} defaultFilename
 * @returns {Promise<{ filename: string }>}
 */
export async function downloadReportCsv(endpoint, defaultFilename) {
    const url = buildApiUrl(endpoint)
    const response = await fetch(url, {
        method: 'GET',
        headers: {
            Accept: 'text/csv, application/json',
        },
        credentials: 'include',
    })

    if (!response.ok) {
        let errJson = null
        try {
            errJson = await response.json()
        } catch {
            // response was not JSON
        }

        if (errJson?.error) {
            throw new ApiError({
                code: errJson.error.code ?? 'EXPORT_FAILED',
                message: errJson.error.message ?? 'Failed to download report.',
                status: response.status,
            })
        }

        throw new ApiError({
            code: 'EXPORT_FAILED',
            message: 'Failed to download report.',
            status: response.status,
        })
    }

    const blob = await response.blob()
    const contentDisposition = response.headers.get('Content-Disposition')
    let filename = defaultFilename

    if (contentDisposition) {
        const match = contentDisposition.match(/filename="?([^";]+)"?/i)
        if (match && match[1]) {
            filename = match[1].trim()
        }
    }

    const blobUrl = window.URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = blobUrl
    anchor.download = filename
    document.body.appendChild(anchor)
    anchor.click()
    document.body.removeChild(anchor)
    window.URL.revokeObjectURL(blobUrl)

    return { filename }
}

export const reportsApi = {
    getSummary: getReportsSummary,
    downloadBills: () => downloadReportCsv('/reports/bills/export', 'spms_bills_history.csv'),
    downloadSolar: () => downloadReportCsv('/reports/solar/export', 'spms_solar_telemetry.csv'),
}
