// SolarAnalyticsService — generation analytics based on stored historical data
// (Phase 3.5). Reads normalized measurements from the repository, computes
// generation totals/averages/peaks, temperature analysis, data completeness,
// and measurement-capability assessment. No HTTP, no SEMS, no fabricated data.
//
// Null = unavailable (never turned into 0). Valid zero = zero. Missing = gap.

const solarMeasurementRepository = require("../repositories/solarMeasurementRepository");
const config = require("../config/env");
const { createSolarError } = require("../utils/solarErrors");

const MAX_RANGE_MS = 90 * 24 * 60 * 60 * 1000;
const RESOLUTIONS = new Set(["raw", "hourly", "daily"]);
const VALID_RANGES = ["today", "7d", "30d", "custom"];

class AnalyticsValidationError extends Error {
    constructor(message, details = null) {
        super(message);
        this.name = "AnalyticsValidationError";
        this.statusCode = 400;
        this.details = details;
    }
    toJSON() {
        return {
            success: false,
            message: this.message,
            code: "VALIDATION_ERROR",
            ...(this.details ? { details: this.details } : {}),
        };
    }
}

function parseIso(value) {
    if (typeof value !== "string" || !value.trim()) return null;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return null;
    return date;
}

function resolveRange(rangeKey, customDays) {
    if (rangeKey === "custom") {
        const days = Number.isFinite(customDays) && customDays > 0 ? customDays : 1;
        const end = new Date();
        const start = new Date(end.getTime() - days * 24 * 60 * 60 * 1000);
        return {
            startTime: start.toISOString(),
            endTime: end.toISOString(),
            rangeKey: "custom",
        };
    }
    const dayMap = { today: 1, "7d": 7, "30d": 30 };
    const days = dayMap[rangeKey] || 7;
    const end = new Date();
    const start = new Date(end.getTime() - days * 24 * 60 * 60 * 1000);
    return { startTime: start.toISOString(), endTime: end.toISOString(), rangeKey };
}

function recommendedResolutionForKey(rangeKey, customDays) {
    if (rangeKey === "today" || (rangeKey === "custom" && (customDays || 1) <= 1)) return "raw";
    if (rangeKey === "7d" || (rangeKey === "custom" && (customDays || 30) <= 7)) return "hourly";
    return "daily";
}

function normalizeQuery(query = {}) {
    const errors = [];

    const rangeKey = query.range || "today";
    if (!VALID_RANGES.includes(rangeKey)) {
        errors.push(`range must be one of: ${VALID_RANGES.join(", ")}`);
    }

    let startTime, endTime;
    if (rangeKey === "custom") {
        startTime = parseIso(query.startTime);
        endTime = parseIso(query.endTime);
        if (!startTime) errors.push("startTime is required for custom range");
        if (!endTime) errors.push("endTime is required for custom range");
    } else {
        const r = resolveRange(rangeKey);
        startTime = parseIso(r.startTime);
        endTime = parseIso(r.endTime);
    }

    if (startTime && endTime) {
        if (endTime.getTime() <= startTime.getTime()) {
            errors.push("endTime must be after startTime");
        }
        if (endTime.getTime() - startTime.getTime() > MAX_RANGE_MS) {
            errors.push("Requested range exceeds the 90-day maximum");
        }
    }

    let resolution = query.resolution || null;
    if (!resolution) {
        resolution = recommendedResolutionForKey(rangeKey, query.customDays ? Number(query.customDays) : 0);
    }
    if (!RESOLUTIONS.has(resolution)) {
        errors.push(`resolution must be one of: ${[...RESOLUTIONS].join(", ")}`);
    }

    const stationId = typeof query.stationId === "string" && query.stationId.trim()
        ? query.stationId.trim() : null;
    const inverterSn = typeof query.inverterSn === "string" && query.inverterSn.trim()
        ? query.inverterSn.trim() : null;

    if (errors.length > 0) {
        throw new AnalyticsValidationError("Invalid analytics query.", errors);
    }

    return {
        startTime: startTime.toISOString(),
        endTime: endTime.toISOString(),
        resolution,
        stationId,
        inverterSn,
    };
}

function resolveScope(userId, { stationId, inverterSn }) {
    if (!userId) {
        throw createSolarError("Authentication required.", "AUTHORIZATION_ERROR");
    }

    const owned = solarMeasurementRepository.findOwnedPairs(userId);
    const ownedStations = new Set(owned.map((p) => p.station_id));
    const ownedInverters = new Set(owned.map((p) => p.inverter_sn));
    if (config.solar.stationId) ownedStations.add(config.solar.stationId);
    if (config.solar.inverterSn) ownedInverters.add(config.solar.inverterSn);

    if (stationId && !ownedStations.has(stationId)) {
        throw createSolarError("Station not found for this user.", "AUTHORIZATION_ERROR");
    }
    if (inverterSn && !ownedInverters.has(inverterSn)) {
        throw createSolarError("Inverter not found for this user.", "AUTHORIZATION_ERROR");
    }

    const effectiveStationId = stationId
        || (owned.length > 0 ? owned[0].station_id : null)
        || config.solar.stationId
        || null;
    const effectiveInverterSn = inverterSn
        || (owned.length > 0 ? owned[0].inverter_sn : null)
        || config.solar.inverterSn
        || null;

    return { stationId: effectiveStationId, inverterSn: effectiveInverterSn };
}

// Assess measurement capability from the historical data in range.
function assessHistoricalCapability(userId, stationId, inverterSn, startTime, endTime) {
    const rows = solarMeasurementRepository.findMeasurementsInRange({
        userId, stationId, inverterSn, startTime, endTime, limit: 500,
    });

    const hasConsumption = rows.some((r) => r.consumption !== null && r.consumption !== undefined);
    const hasGrid = rows.some((r) => r.grid_power !== null && r.grid_power !== undefined);
    const hasGeneration = rows.some((r) => r.power !== null && r.power !== undefined);

    return {
        generation: hasGeneration ? "AVAILABLE" : "UNAVAILABLE",
        consumption: hasConsumption ? "AVAILABLE" : "UNAVAILABLE",
        grid: hasGrid ? "AVAILABLE" : "UNAVAILABLE",
        battery: "UNAVAILABLE",
        energyBalance: (hasGeneration && hasConsumption) ? "AVAILABLE" : "INSUFFICIENT_DATA",
    };
}

// Connection analytics from stored connection_status values (best effort).
// Never claims an inverter was offline merely because one measurement is
// missing — only actual stored connection states are counted.
function computeConnectionAnalytics(rows) {
    if (!rows || rows.length === 0) {
        return { lastKnownStatus: null, statusCounts: {}, sampled: 0 };
    }
    const counts = {};
    let lastStatus = null;
    let lastTime = null;
    for (const row of rows) {
        const status = row.connection_status;
        if (status) {
            counts[status] = (counts[status] || 0) + 1;
            if (!lastTime || (row.measurement_time || "") >= lastTime) {
                lastTime = row.measurement_time || lastTime;
                lastStatus = status;
            }
        }
    }
    return { lastKnownStatus: lastStatus, statusCounts: counts, sampled: rows.length };
}

// Compute generation analytics from aggregated rows.
function computeGenerationAnalytics(points, resolution) {
    if (!points || points.length === 0) {
        return { total: null, unit: "kWh", peakPower: null, peakPowerUnit: "kW", peakTime: null, avgPower: null, avgPowerUnit: "kW", count: 0 };
    }

    // Peak power across all points.
    let peakPower = -Infinity;
    let peakTime = null;

    for (const p of points) {
        const pwr = p.maxPower !== undefined ? p.maxPower : p.power;
        if (pwr !== null && pwr !== undefined && pwr > peakPower) {
            peakPower = pwr;
            peakTime = p.timestamp;
        }
    }

    if (peakPower === -Infinity) {
        peakPower = null;
        peakTime = null;
    }

    // Average power (uses avgPower if available, else power).
    let totalPower = 0;
    let powerCount = 0;
    for (const p of points) {
        const pwr = p.avgPower !== undefined ? p.avgPower : p.power;
        if (pwr !== null && pwr !== undefined) {
            totalPower += pwr;
            powerCount++;
        }
    }
    const avgPower = powerCount > 0 ? totalPower / powerCount : null;

    // Total generation energy:
    // - daily: sum of daily generation values (each is the day's cumulative total)
    // - hourly: sum of avg_power (kWh when avg kW × 1h)
    // - raw: integral of power × 5min intervals
    let total = null;
    if (resolution === "daily") {
        const sum = points.reduce((a, p) => a + (p.generation !== null && p.generation !== undefined ? p.generation : 0), 0);
        total = sum > 0 ? sum : null;
    } else if (resolution === "hourly") {
        // avgPower kW × 1h = kWh per bucket. This is an estimate.
        const sum = points.reduce((a, p) => a + ((p.avgPower !== null && p.avgPower !== undefined) ? p.avgPower : 0), 0);
        total = sum > 0 ? sum : null;
    } else {
        // raw: each point is ~5 min = 1/12 hour. Energy ≈ power × (1/12) kWh.
        // Sum all contributions that have valid power.
        const sum = points.reduce((a, p) => a + ((p.power !== null && p.power !== undefined) ? p.power / 12 : 0), 0);
        total = sum > 0 ? parseFloat(sum.toFixed(2)) : null;
    }

    return {
        total,
        unit: "kWh",
        peakPower: peakPower !== null ? parseFloat(peakPower.toFixed(3)) : null,
        peakPowerUnit: "kW",
        peakTime,
        avgPower: avgPower !== null ? parseFloat(avgPower.toFixed(3)) : null,
        avgPowerUnit: "kW",
        count: powerCount,
    };
}

// Temperature analytics from points.
function computeTemperatureAnalytics(points) {
    if (!points || points.length === 0) {
        return { avg: null, unit: "°C", peak: null, min: null, count: 0 };
    }

    let sum = 0;
    let count = 0;
    let peak = -Infinity;
    let min = Infinity;

    for (const p of points) {
        const temp = p.temperature || p.avgTemperature;
        if (temp !== null && temp !== undefined) {
            sum += temp;
            count++;
            if (temp > peak) peak = temp;
            if (temp < min) min = temp;
        }
    }

    return {
        avg: count > 0 ? parseFloat((sum / count).toFixed(2)) : null,
        unit: "°C",
        peak: peak !== -Infinity ? parseFloat(peak.toFixed(1)) : null,
        min: min !== Infinity ? parseFloat(min.toFixed(1)) : null,
        count,
    };
}

// Data completeness: expected vs actual measurement count.
function computeDataCompleteness(rangeStart, rangeEnd, points, resolution) {
    const startMs = new Date(rangeStart).getTime();
    const endMs = new Date(rangeEnd).getTime();
    const durationMs = endMs - startMs;

    let intervalMs;
    if (resolution === "raw") intervalMs = 5 * 60 * 1000;
    else if (resolution === "hourly") intervalMs = 60 * 60 * 1000;
    else intervalMs = 24 * 60 * 60 * 1000;

    const expected = Math.max(0, Math.floor(durationMs / intervalMs) + 1);
    const actual = points ? points.length : 0;
    const missing = Math.max(0, expected - actual);
    const partial = points ? points.filter((p) => p.quality === "PARTIAL").length : 0;
    const completenessPercent = expected > 0 ? parseFloat(((actual / expected) * 100).toFixed(2)) : 0;

    return {
        expected,
        actual,
        missing,
        partial,
        completenessPercent: Math.min(completenessPercent, 100),
    };
}

// Map repository rows to normalized chart points.
function mapPoints(rows, resolution, includeQuality) {
    if (resolution === "raw") {
        return rows.map((row) => {
            const p = {
                timestamp: row.measurement_time,
                power: row.power,
                generation: row.daily_generation,
                consumption: row.consumption,
                grid: row.grid_power,
                temperature: row.temperature,
            };
            if (includeQuality) p.quality = row.quality;
            return p;
        });
    }
    if (resolution === "hourly") {
        return rows.map((row) => {
            const p = {
                timestamp: row.bucket,
                power: row.avgPower,
                avgPower: row.avgPower,
                maxPower: row.maxPower,
                minPower: row.minPower,
                generation: null,
                consumption: null,
                grid: null,
                temperature: row.avgTemperature,
            };
            if (includeQuality) {
                p.quality = row.hasInvalid ? "INVALID" : (row.partialCount > 0 ? "PARTIAL" : "VALID");
            }
            return p;
        });
    }
    return rows.map((row) => {
        const p = {
            timestamp: `${row.bucket}T00:00:00.000Z`,
            power: row.avgPower,
            avgPower: row.avgPower,
            maxPower: row.maxPower,
            minPower: row.minPower,
            generation: row.generation,
            consumption: null,
            grid: null,
            temperature: row.avgTemperature,
        };
        if (includeQuality) {
            p.quality = row.hasInvalid ? "INVALID" : (row.partialCount > 0 ? "PARTIAL" : "VALID");
        }
        return p;
    });
}

// Main entry point: build the structured analytics response.
function getAnalytics(userId, query) {
    const normalized = normalizeQuery(query);
    const scope = resolveScope(userId, normalized);
    const { startTime, endTime, resolution } = normalized;

    const capabilities = assessHistoricalCapability(userId, scope.stationId, scope.inverterSn, startTime, endTime);

    let rows;
    if (resolution === "raw") {
        rows = solarMeasurementRepository.findMeasurementsInRange({
            userId, stationId: scope.stationId, inverterSn: scope.inverterSn,
            startTime, endTime, limit: 5000,
        });
    } else if (resolution === "hourly") {
        rows = solarMeasurementRepository.findHourlyAggregates({
            userId, stationId: scope.stationId, inverterSn: scope.inverterSn, startTime, endTime,
        });
    } else {
        rows = solarMeasurementRepository.findDailyAggregates({
            userId, stationId: scope.stationId, inverterSn: scope.inverterSn, startTime, endTime,
        });
    }

    const points = mapPoints(rows, resolution, true);
    const generation = computeGenerationAnalytics(points, resolution);
    const temperature = computeTemperatureAnalytics(points);
    const dataQuality = computeDataCompleteness(startTime, endTime, points, resolution);
    const connection = computeConnectionAnalytics(rows);

    return {
        range: { startTime, endTime },
        resolution,
        stationId: scope.stationId,
        inverterSn: scope.inverterSn,
        capabilities,
        generation,
        temperature,
        dataQuality,
        connection,
        points,
    };
}

module.exports = {
    getAnalytics,
    normalizeQuery,
    resolveRange,
    recommendedResolutionForKey,
    AnalyticsValidationError,
    MAX_RANGE_MS,
    RESOLUTIONS,
    VALID_RANGES,
};