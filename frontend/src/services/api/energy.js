import { apiClient } from './client'

/**
 * SPMS Energy Balance API module (F4 — docs/02 §2, docs/04 §60).
 *
 * Thin endpoint definitions over the central API client. No business
 * logic here: the backend is the sole authority for classification.
 */

/**
 * Classify an energy balance from provided inputs.
 *
 * @param {Object} payload
 * @param {'TIME_PERIOD'|'AGGREGATE'} [payload.analysisType]
 * @param {{valueKwh:number, dataNature:string, dataStatus:string, quality?:string, confidenceLevel?:string}} payload.solarGeneration
 * @param {{valueKwh:number, dataNature:string, dataStatus:string, quality?:string, confidenceLevel?:string}} payload.householdConsumption
 * @param {{start?:string, end?:string, resolution?:string}} [payload.period]
 * @param {string} [payload.householdId]
 * @param {AbortSignal} [signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function classifyEnergyBalance(payload, signal = undefined) {
    return apiClient.post('/energy/balance', {
        analysis_type: payload.analysisType,
        solar_generation: {
            value_kwh: payload.solarGeneration.valueKwh,
            data_nature: payload.solarGeneration.dataNature,
            data_status: payload.solarGeneration.dataStatus,
            ...(payload.solarGeneration.quality ? { quality: payload.solarGeneration.quality } : {}),
            ...(payload.solarGeneration.confidenceLevel
                ? { confidence_level: payload.solarGeneration.confidenceLevel }
                : {}),
        },
        household_consumption: {
            value_kwh: payload.householdConsumption.valueKwh,
            data_nature: payload.householdConsumption.dataNature,
            data_status: payload.householdConsumption.dataStatus,
            ...(payload.householdConsumption.quality ? { quality: payload.householdConsumption.quality } : {}),
            ...(payload.householdConsumption.confidenceLevel
                ? { confidence_level: payload.householdConsumption.confidenceLevel }
                : {}),
        },
        ...(payload.analysisType === 'TIME_PERIOD' && payload.period
            ? {
                  period: {
                      ...(payload.period.start ? { start: payload.period.start } : {}),
                      ...(payload.period.end ? { end: payload.period.end } : {}),
                      ...(payload.period.resolution ? { resolution: payload.period.resolution } : {}),
                  },
              }
            : {}),
        ...(payload.householdId ? { household_id: payload.householdId } : {}),
    }, { signal })
}

/**
 * Fetch the currently stored energy balance situation. Until real data
 * sources exist, the backend answers with an honest UNAVAILABLE result.
 *
 * @param {string} [householdId]
 * @param {AbortSignal} [signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function getCurrentEnergyBalance(householdId = undefined, signal = undefined) {
    return apiClient.get('/energy/balance', {
        query: householdId ? { household_id: householdId } : undefined,
        signal,
    })
}
