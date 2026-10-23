import { buildApiUrl } from './config'
import { apiClient, ApiError } from './client'

/**
 * SPMS Reporting API (docs/02 §2 F8, docs/03 §4).
 *
 * Provides household-scoped monthly reporting, executive PDF report downloads,
 * and detailed analytical Excel workbooks.
 */

/**
 * Fetch authoritative monthly household energy report payload.
 *
 * @param {string} [month] - Target month in YYYY-MM format (optional)
 * @param {AbortSignal} [signal]
 * @returns {Promise<any>}
 */
export async function getMonthlyReport(month, signal) {
    const query = month ? `?month=${encodeURIComponent(month)}` : ''
    const { data } = await apiClient.get(`/reports/monthly${query}`, { signal })
    return data
}

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
 * Triggers a secure, programmatic file download (PDF or XLSX) for a specified reports endpoint.
 * Preserves HttpOnly session credentials and parses HTTP Content-Disposition filename.
 *
 * @param {string} endpoint - Relative path (e.g. '/reports/monthly/export/pdf?month=2026-09')
 * @param {string} defaultFilename
 * @param {string} [acceptHeader]
 * @returns {Promise<{ filename: string }>}
 */
export async function downloadReportFile(endpoint, defaultFilename, acceptHeader = 'application/octet-stream, application/json') {
    const url = buildApiUrl(endpoint)
    const response = await fetch(url, {
        method: 'GET',
        headers: {
            Accept: acceptHeader,
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
    getMonthly: (month, signal) => getMonthlyReport(month, signal),
    getSummary: getReportsSummary,
    downloadPdf: (month) => {
        const query = month ? `?month=${encodeURIComponent(month)}` : ''
        return downloadReportFile(
            `/reports/monthly/export/pdf${query}`,
            `SPMS_Monthly_Energy_Report_${month || 'current'}.pdf`,
            'application/pdf, application/json'
        )
    },
    downloadExcel: (month) => {
        const query = month ? `?month=${encodeURIComponent(month)}` : ''
        return downloadReportFile(
            `/reports/monthly/export/excel${query}`,
            `SPMS_Monthly_Energy_Report_${month || 'current'}.xlsx`,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json'
        )
    },
}
