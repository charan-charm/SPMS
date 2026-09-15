import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, apiClient, setCsrfToken } from './client'
import { processBill, uploadBill } from './bills'

/**
 * Central client contract for the B4 additions (no second fetch path):
 * multipart uploads ride the SAME client — no JSON encoding, no manual
 * Content-Type (the browser must own the boundary), session cookies
 * included, CSRF forwarded — and long-running bill processing gets its
 * per-request timeout without touching anyone else's 15s default.
 */

function envelopeResponse(data, status = 200) {
    return {
        ok: status < 400,
        status,
        headers: new Headers({ 'X-Request-Id': 'req-test' }),
        text: async () => JSON.stringify({ success: true, data, meta: { request_id: 'req-test' } }),
    }
}

describe('api client — bills transport', () => {
    beforeEach(() => {
        setCsrfToken('csrf-test-token')
        vi.stubGlobal('fetch', vi.fn(async () => envelopeResponse({ ok: true }, 201)))
    })

    afterEach(() => {
        setCsrfToken(null)
        vi.unstubAllGlobals()
    })

    it('sends the file as multipart with exactly one part and no JSON headers', async () => {
        const file = new File([new Uint8Array([0x89, 0x50, 0x4e, 0x47])], 'my-bill.png', {
            type: 'image/png',
        })

        await uploadBill(file)

        const fetchMock = globalThis.fetch
        expect(fetchMock).toHaveBeenCalledTimes(1)
        const [url, init] = fetchMock.mock.calls[0]
        expect(url).toBe('/api/v1/bills/upload')
        expect(init.method).toBe('POST')
        expect(init.credentials).toBe('include')
        expect(init.body).toBeInstanceOf(FormData)
        expect([...init.body.keys()]).toEqual(['file'])
        expect(init.body.get('file')).toBe(file)
        // The browser owns the multipart boundary; a manual
        // Content-Type here would break the upload.
        expect(init.headers['Content-Type']).toBeUndefined()
        expect(init.headers.Accept).toBe('application/json')
        expect(init.headers['X-SPMS-CSRF']).toBe('csrf-test-token')
    })

    it('gives processing its long timeout and plain requests the default', async () => {
        const timeoutSpy = vi.spyOn(AbortSignal, 'timeout')

        await apiClient.get('/bills')
        expect(timeoutSpy).toHaveBeenLastCalledWith(15000)

        await processBill('11111111-2222-4333-8444-555555555555')
        expect(timeoutSpy).toHaveBeenLastCalledWith(300000)

        await uploadBill(new File(['x'], 'a.png'))
        expect(timeoutSpy).toHaveBeenLastCalledWith(60000)

        timeoutSpy.mockRestore()
    })

    it('maps transport failures to stable ApiError codes', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                const error = new Error('aborted')
                error.name = 'TimeoutError'
                throw error
            }),
        )

        await expect(processBill('11111111-2222-4333-8444-555555555555')).rejects.toMatchObject({
            name: 'ApiError',
            code: 'TIMEOUT',
        })

        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: false,
                status: 409,
                headers: new Headers(),
                text: async () =>
                    JSON.stringify({
                        success: false,
                        error: { code: 'INVALID_BILL_STATE', message: 'Only a PROCESSING bill can be extracted.' },
                    }),
            })),
        )

        const error = await processBill('11111111-2222-4333-8444-555555555555').catch((e) => e)
        expect(error).toBeInstanceOf(ApiError)
        expect(error.code).toBe('INVALID_BILL_STATE')
        expect(error.status).toBe(409)
    })
})
