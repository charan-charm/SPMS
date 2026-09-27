// HistoricalSolarService — business logic for the historical solar data API
// (Phase 3.3.2). Reads normalized measurements from the repository, validates
// the query contract, enforces ownership, and returns a chart-oriented
// normalized response. No HTTP logic, no SEMS/provider logic, no analytics.
//
// Distinctions preserved everywhere:
//   power   → kW (instantaneous / averaged)
//   energy  → kWh (cumulative counters, never summed blindly)
//   NULL    → unavailable/not measured (never turned into 0)
//   missing → a gap (no point), never fabricated as 0

const solarMeasurementRepository = require("../repositories/solarMeasurementRepository");
const config = require("../config/env");
const { createSolarError } = require("../utils/solarErrors");

const MAX_RANGE_MS = 90 * 24 * 60 * 60 * 1000; // 90 days (Phase 3.3.0 §26)
const RESOLUTIONS = new Set(["raw", "hourly", "daily"]);
const MAX_RAW_LIMIT = 1000;
const DEFAULT_RAW_LIMIT = 500;

class HistoryValidationError extends Error {
    constructor(message, details = null) {
        super(message);
        this.name = "HistoryValidationError";
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

// Parse an ISO-8601 timestamp strictly. Returns Date or null.
function parseIso(value) {
    if (typeof value !== "string" || !value.trim()) return null;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return null;
    return date;
}

// Validate + normalize the query contract.
function normalizeQuery(query = {}) {
    const errors = [];

    // Required ISO timestamps.
    const startTime = parseIso(query.startTime);
    if (!query.startTime || !startTime) {
        errors.push("startTime must be a valid ISO-8601 timestamp");
    }
    const endTime = parseIso(query.endTime);
    if (!query.endTime || !endTime) {
        errors.push("endTime must be a valid ISO-8601 timestamp");
    }

    if (startTime && endTime) {
        if (endTime.getTime() <= startTime.getTime()) {
            errors.push("endTime must be after startTime");
        }
        if (endTime.getTime() - startTime.getTime() > MAX_RANGE_MS) {
            errors.push("Requested range exceeds the 90-day maximum");
        }
    }

    // Resolution.
    const resolution = query.resolution || "raw";
    if (!RESOLUTIONS.has(resolution)) {
        errors.push(`resolution must be one of: ${[...RESOLUTIONS].join(", ")}`);
    }

    // Ownership-scoping filters (validated against owned pairs in the service).
    const stationId = typeof query.stationId === "string" && query.stationId.trim()
        ? query.stationId.trim()
        : null;
    const inverterSn = typeof query.inverterSn === "string" && query.inverterSn.trim()
        ? query.inverterSn.trim()
        : null;

    // includeQuality: preserve quality per point when requested.
    const includeQuality = query.includeQuality === "true" || query.includeQuality === "1";

    // Raw pagination/limits.
    let limit = DEFAULT_RAW_LIMIT;
    if (query.limit !== undefined) {
        const parsed = Number(query.limit);
        if (!Number.isInteger(parsed) || parsed < 1) {
            errors.push("limit must be a positive integer");
        } else {
            limit = Math.min(parsed, MAX_RAW_LIMIT);
        }
    }

    if (errors.length > 0) {
        throw new HistoryValidationError("Invalid historical data query.", errors);
    }

    return {
        startTime: startTime.toISOString(),
        endTime: endTime.toISOString(),
        resolution,
        stationId,
        inverterSn,
        includeQuality,
        limit,
    };
}

// Resolve effective station/inverter scope. If the caller provided filters they
// must match a station/inverter pair owned by the user (verified against the
// historical data — the ownership boundary is user_id, never frontend values).
// The configured station/inverter (config.solar) is treated as owned, so a
// fresh install with no historical rows yet is still queryable for its own
// configured station/inverter.
// When no filters are given, fall back to the user's owned pairs (or the
// configured station).
function resolveScope(userId, { stationId, inverterSn }) {
    const owned = solarMeasurementRepository.findOwnedPairs(userId);

    const ownedStations = new Set(owned.map((p) => p.station_id));
    const ownedInverters = new Set(owned.map((p) => p.inverter_sn));

    // The configured station/inverter belongs to the owner (single-owner model).
    if (config.solar.stationId) ownedStations.add(config.solar.stationId);
    if (config.solar.inverterSn) ownedInverters.add(config.solar.inverterSn);

    if (stationId && !ownedStations.has(stationId)) {
        throw createSolarError(
            "Station not found for this user.",
            "AUTHORIZATION_ERROR"
        );
    }
    if (inverterSn && !ownedInverters.has(inverterSn)) {
        throw createSolarError(
            "Inverter not found for this user.",
            "AUTHORIZATION_ERROR"
        );
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

// Detect whether a range has gaps: the raw resolution has an expected number of
// 5-minute buckets; fewer points than expected means some cycles were missed.
function computeHasGaps({ startTime, endTime, resolution, points }) {
    if (points.length === 0) return false;
    if (resolution === "raw") {
        const intervalMs = 5 * 60 * 1000;
        const expected = Math.floor(
            (new Date(endTime).getTime() - new Date(startTime).getTime()) / intervalMs
        ) + 1;
        return points.length < expected;
    }
    // For hourly/daily, a bucket count below the ideal is normal at the edges;
    // gaps are still meaningful if a whole bucket is missing in the middle.
    return false;
}

// Map raw repository rows → chart points.
function rawRowsToPoints(rows, includeQuality) {
    return rows.map((row) => {
        const point = {
            timestamp: row.measurement_time,
            power: row.power,           // kW (0 is valid; null = missing)
            generation: row.daily_generation, // kWh cumulative day counter
            consumption: row.consumption,     // null = unavailable
            grid: row.grid_power,             // null = unavailable
            temperature: row.temperature,     // °C
        };
        if (includeQuality) point.quality = row.quality;
        return point;
    });
}

// Map hourly aggregation rows → chart points (power only; no energy at hourly
// resolution because daily counters must not be summed into hourly energy).
function hourlyRowsToPoints(rows, includeQuality) {
    return rows.map((row) => {
        const point = {
            timestamp: row.bucket,
            power: row.avgPower,        // average power over the hour (kW)
            maxPower: row.maxPower,
            minPower: row.minPower,
            generation: null,           // not derivable without summing counters
            consumption: null,
            grid: null,
            temperature: row.avgTemperature,
        };
        if (includeQuality) {
            point.quality = row.hasInvalid ? "INVALID" : (row.partialCount > 0 ? "PARTIAL" : "VALID");
        }
        return point;
    });
}

// Map daily aggregation rows → chart points.
function dailyRowsToPoints(rows, includeQuality) {
    return rows.map((row) => {
        const point = {
            timestamp: `${row.bucket}T00:00:00.000Z`,
            power: row.avgPower,        // average power over the day (kW)
            maxPower: row.maxPower,
            minPower: row.minPower,
            generation: row.generation, // final daily cumulative total (kWh)
            consumption: null,
            grid: null,
            temperature: row.avgTemperature,
        };
        if (includeQuality) {
            point.quality = row.hasInvalid ? "INVALID" : (row.partialCount > 0 ? "PARTIAL" : "VALID");
        }
        return point;
    });
}

// Primary entry point: build the normalized historical response.
function getHistory(userId, query) {
    if (!userId) {
        throw createSolarError("Authentication required.", "AUTHORIZATION_ERROR");
    }

    const normalized = normalizeQuery(query);
    const scope = resolveScope(userId, normalized);

    const { startTime, endTime, resolution, includeQuality, limit } = normalized;

    let points;
    if (resolution === "raw") {
        const rows = solarMeasurementRepository.findMeasurementsInRange({
            userId,
            stationId: scope.stationId,
            inverterSn: scope.inverterSn,
            startTime,
            endTime,
            limit,
        });
        points = rawRowsToPoints(rows, includeQuality);
    } else if (resolution === "hourly") {
        const rows = solarMeasurementRepository.findHourlyAggregates({
            userId,
            stationId: scope.stationId,
            inverterSn: scope.inverterSn,
            startTime,
            endTime,
        });
        points = hourlyRowsToPoints(rows, includeQuality);
    } else {
        const rows = solarMeasurementRepository.findDailyAggregates({
            userId,
            stationId: scope.stationId,
            inverterSn: scope.inverterSn,
            startTime,
            endTime,
        });
        points = dailyRowsToPoints(rows, includeQuality);
    }

    const hasGaps = computeHasGaps({ startTime, endTime, resolution, points });
    const partialCount = points.filter((p) => p.quality === "PARTIAL").length;

    return {
        history: {
            range: {
                startTime,
                endTime,
                resolution,
            },
            stationId: scope.stationId,
            inverterSn: scope.inverterSn,
            units: {
                power: "kW",
                energy: "kWh",
                temperature: "°C",
                voltage: "V",
                current: "A",
                frequency: "Hz",
                powerFactor: "unitless",
            },
            points,
            meta: {
                sampleCount: points.length,
                hasGaps,
                partialCount,
                resolution,
            },
        },
    };
}

module.exports = {
    getHistory,
    normalizeQuery,
    resolveScope,
    HistoryValidationError,
    MAX_RANGE_MS,
    RESOLUTIONS,
};
