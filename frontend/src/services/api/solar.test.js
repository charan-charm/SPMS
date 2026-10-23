import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiClient } from './client'
import { solarApi } from './solar'

describe('solarApi client timeout configuration', () => {
    let postSpy

    beforeEach(() => {
        postSpy = vi.spyOn(apiClient, 'post').mockResolvedValue({ data: { test_result: { success: true } } })
    })

    afterEach(() => {
        vi.restoreAllMocks()
    })

    it('configures 30,000ms timeout for testConnection', async () => {
        const credentials = { account: 'test@example.com', password: 'secretPassword' }
        await solarApi.testConnection(credentials)

        expect(postSpy).toHaveBeenCalledWith(
            '/solar/connection/test',
            credentials,
            expect.objectContaining({ timeoutMs: 30000 })
        )
    })

    it('configures 30,000ms timeout for saveConnection', async () => {
        const credentials = { account: 'test@example.com', password: 'secretPassword', station_id: 'st-1' }
        await solarApi.saveConnection(credentials)

        expect(postSpy).toHaveBeenCalledWith(
            '/solar/connection',
            credentials,
            expect.objectContaining({ timeoutMs: 30000 })
        )
    })

    it('posts to /solar/sync/auto for opportunistic visit synchronization', async () => {
        postSpy.mockResolvedValueOnce({ data: { synced: true, status: 'SUCCESS' } })
        const res = await solarApi.autoSyncTelemetry()

        expect(postSpy).toHaveBeenCalledWith(
            '/solar/sync/auto',
            {},
            expect.objectContaining({ signal: undefined })
        )
        expect(res).toEqual({ synced: true, status: 'SUCCESS' })
    })
})
