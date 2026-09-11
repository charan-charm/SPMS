import { apiClient } from './client'

/**
 * SPMS Energy Cost API module (F5 — docs/02 §2, docs/01 §19, docs/10 D-042).
 *
 * Thin endpoint definitions over the central API client. No business or
 * numerical cost logic here: the backend is the sole authority for cost
 * calculation.
 */

/**
 * Compute an honest future estimated cost from validated inputs and an
 * explicitly declared pricing basis.
 *
 * @param {Object} payload
 * @param {{valueKwh:number, dataNature:string, dataStatus:string, quality?:string, confidenceLevel?:string}} payload.solarGeneration
 * @param {{valueKwh:number, dataNature:string, dataStatus:string, quality?:string, confidenceLevel?:string}} payload.householdConsumption
 * @param {{basis:string, unitRate?:number, currency:string, source?:string}} payload.pricing
 * @param {{amount:number, currency:string, label?:string}} [payload.fixedCharge]
 * @param {{start?:string, end?:string, resolution?:string}} [payload.period]
 * @param {string} [payload.householdId]
 * @param {AbortSignal} [signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function estimateEnergyCost(payload, signal = undefined) {
    return apiClient.post('/energy/cost/estimate', {
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
        pricing: {
            basis: payload.pricing.basis,
            ...(payload.pricing.unitRate !== undefined && payload.pricing.unitRate !== null
                ? { unit_rate: payload.pricing.unitRate }
                : {}),
            currency: payload.pricing.currency,
            ...(payload.pricing.source ? { source: payload.pricing.source } : {}),
        },
        ...(payload.fixedCharge
            ? {
                  fixed_charge: {
                      amount: payload.fixedCharge.amount,
                      currency: payload.fixedCharge.currency,
                      ...(payload.fixedCharge.label ? { label: payload.fixedCharge.label } : {}),
                  },
              }
            : {}),
        ...(payload.period
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
 * Fetch the verified historical cost. Until verified bill persistence
 * exists, the backend answers with an honest UNAVAILABLE result.
 *
 * @param {string} [householdId]
 * @param {AbortSignal} [signal]
 * @returns {Promise<{data: Object, meta: Object, requestId: string|null, status: number}>}
 */
export function getHistoricalEnergyCost(householdId = undefined, signal = undefined) {
    return apiClient.get('/energy/cost/historical', {
        query: householdId ? { household_id: householdId } : undefined,
        signal,
    })
}
