// EnergyBalanceService — business logic for the measurement-aware energy-flow
// module (Phase 3.4).
//
// Responsibilities:
//   - Fetch the current normalized snapshot via SolarService (provider-agnostic)
//   - Build the measurement capability model
//   - Decide whether a full energy balance can be calculated
//   - Return a structured, honest result — INSUFFICIENT_DATA when required
//     measurements are missing (never fake numbers)
//   - Enforce ownership (single-owner model: configured station/inverter belong
//     to the authenticated user)
//
// No HTTP logic, no SQL, no SEMS/provider logic. All calculation lives in the
// pure `energyBalance` utility module.

const solarService = require("./solarService");
const solarMeasurementRepository = require("../repositories/solarMeasurementRepository");
const config = require("../config/env");
const { createSolarError } = require("../utils/solarErrors");
const energyBalance = require("../utils/energyBalance");

// Resolve the station/inverter the user owns. Mirrors the historical service:
// configured station/inverter always belongs to the owner; owned pairs from the
// measurements table are the secondary source (single-owner system).
function resolveOwnedScope(userId) {
    if (!userId) {
        throw createSolarError("Authentication required.", "AUTHORIZATION_ERROR");
    }

    const owned = solarMeasurementRepository.findOwnedPairs(userId);
    const ownedStations = new Set(owned.map((p) => p.station_id));
    const ownedInverters = new Set(owned.map((p) => p.inverter_sn));
    if (config.solar.stationId) ownedStations.add(config.solar.stationId);
    if (config.solar.inverterSn) ownedInverters.add(config.solar.inverterSn);

    const stationId = config.solar.stationId
        || (owned.length > 0 ? owned[0].station_id : null);
    const inverterSn = config.solar.inverterSn
        || (owned.length > 0 ? owned[0].inverter_sn : null);

    return { stationId, inverterSn, ownedStations, ownedInverters };
}

// Get the current energy-flow assessment for the authenticated user.
async function getCurrentFlow(userId, options = {}) {
    const scope = resolveOwnedScope(userId);

    // Fetch the normalized snapshot (provider-independent; mock or SEMS).
    const snapshot = await solarService.getCurrentSnapshot();

    // Ownership guard: the snapshot's station must belong to the user. In the
    // single-owner model this is always true for the configured station, but it
    // is enforced explicitly so a cross-user station can never leak.
    if (
        snapshot &&
        snapshot.powerStationId &&
        scope.ownedStations.size > 0 &&
        !scope.ownedStations.has(snapshot.powerStationId)
    ) {
        throw createSolarError("Access denied to this solar data.", "AUTHORIZATION_ERROR");
    }

    const result = energyBalance.assessEnergyFlow(snapshot, snapshot?.provider, {
        tolerance: options.tolerance ?? energyBalance.DEFAULT_TOLERANCE_KW,
    });

    return {
        flow: {
            status: result.status,
            timestamp: result.timestamp,
            stationId: scope.stationId || snapshot?.powerStationId || null,
            inverterSn: scope.inverterSn || snapshot?.inverterId || null,
            provider: snapshot?.provider || null,
            measurements: result.measurements,
            balance: result.balance,
            potentialExcess: result.potentialExcess,
            missingMeasurements: result.missingMeasurements,
            message: result.message,
            units: {
                power: "kW",
                energy: "kWh",
            },
            meta: {
                tolerance: options.tolerance ?? energyBalance.DEFAULT_TOLERANCE_KW,
                measurementBoundary: "inverter-ac-output",
            },
        },
    };
}

// EnergyBalanceService — business logic for the measurement-aware energy-flow
// module (Phase 3.4 + gap closure).
//
// Responsibilities:
//   - Fetch the current normalized snapshot via SolarService (provider-agnostic)
//   - Build the measurement capability model
//   - Decide whether a full energy balance can be calculated
//   - Return a structured, honest result — INSUFFICIENT_DATA when required
//     measurements are missing (never fake numbers)
//   - Historical energy flow: per-bucket balance over STORED measurements
//     (GET /api/energy-flow/history) — no repeated SEMS calls
//   - Enforce ownership (single-owner model: configured station/inverter belong
//     to the authenticated user)
//
// No HTTP logic, no SQL, no SEMS/provider logic. All calculation lives in the
// pure `energyBalance` utility module.

const MAX_RANGE_MS = 90 * 24 * 60 * 60 * 1000;
const RESOLUTIONS = new Set(["raw", "hourly", "daily"]);
const DEFAULT_RAW_LIMIT = 500;
const MAX_RAW_LIMIT = 1000;

class EnergyFlowValidationError extends Error {
    constructor(message, details = null) {
        super(message);
        this.name = "EnergyFlowValidationError";
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

// Validate the historical energy-flow query contract.
function normalizeHistoryQuery(query = {}) {
    const errors = [];

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

    const resolution = query.resolution || "raw";
    if (!RESOLUTIONS.has(resolution)) {
        errors.push(`resolution must be one of: ${[...RESOLUTIONS].join(", ")}`);
    }

    const stationId = typeof query.stationId === "string" && query.stationId.trim()
        ? query.stationId.trim() : null;
    const inverterSn = typeof query.inverterSn === "string" && query.inverterSn.trim()
        ? query.inverterSn.trim() : null;
    const includeQuality = query.includeQuality === "true" || query.includeQuality === "1";

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
        throw new EnergyFlowValidationError("Invalid energy-flow history query.", errors);
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

// Historical energy flow: per-bucket balance from STORED measurements.
function getHistory(userId, query) {
    if (!userId) {
        throw createSolarError("Authentication required.", "AUTHORIZATION_ERROR");
    }

    const normalized = normalizeHistoryQuery(query);
    const scope = resolveOwnedScope(userId);
    const { startTime, endTime, resolution, includeQuality, limit } = normalized;

    // Reject cross-user station/inverter before any data is read (matches the
    // historical/analytics services: a provided station/inverter must be owned).
    if (
        normalized.stationId &&
        !scope.ownedStations.has(normalized.stationId)
    ) {
        throw createSolarError("Station not found for this user.", "AUTHORIZATION_ERROR");
    }
    if (
        normalized.inverterSn &&
        !scope.ownedInverters.has(normalized.inverterSn)
    ) {
        throw createSolarError("Inverter not found for this user.", "AUTHORIZATION_ERROR");
    }

    const stationId = normalized.stationId || scope.stationId;
    const inverterSn = normalized.inverterSn || scope.inverterSn;

    let rows;
    if (resolution === "raw") {
        rows = solarMeasurementRepository.findMeasurementsInRange({
            userId, stationId, inverterSn, startTime, endTime, limit,
        });
    } else if (resolution === "hourly") {
        rows = solarMeasurementRepository.findHourlyAggregates({
            userId, stationId, inverterSn, startTime, endTime,
        });
    } else {
        rows = solarMeasurementRepository.findDailyAggregates({
            userId, stationId, inverterSn, startTime, endTime,
        });
    }

    const points = mapHistoricalPoints(rows, resolution, includeQuality);
    const capabilities = assessRangeCapabilities(points);

    return {
        history: {
            range: { startTime, endTime, resolution },
            stationId,
            inverterSn,
            capabilities,
            units: {
                power: "kW",
                energy: "kWh",
            },
            points,
            meta: {
                sampleCount: points.length,
                resolution,
            },
        },
    };
}

// Map stored rows to per-bucket energy-flow points. Each bucket evaluates the
// measurement-aware capability model. Missing consumption/grid/battery are
// never converted to zero; per-bucket status reflects capability.
function mapHistoricalPoints(rows, resolution, includeQuality) {
    return rows.map((row) => {
        const snapshot = rowSnapshot(row, resolution);
        const assessment = energyBalance.assessEnergyFlow(snapshot, null);

        const point = {
            timestamp: snapshot.timestamp,
            generation: assessment.measurements.generation.value,
            consumption: assessment.measurements.consumption.value,
            gridImport: assessment.measurements.gridFlow.value !== null && assessment.measurements.gridFlow.value < 0
                ? -assessment.measurements.gridFlow.value : null,
            gridExport: assessment.measurements.gridFlow.value !== null && assessment.measurements.gridFlow.value >= 0
                ? assessment.measurements.gridFlow.value : null,
            batteryCharge: null,
            batteryDischarge: null,
            balanceError: assessment.balance ? assessment.balance.value : null,
            status: assessment.status,
        };
        if (includeQuality) point.quality = row.quality || inferQuality(row, resolution);
        return point;
    });
}

// Build a normalized snapshot shape for a stored row.
function rowSnapshot(row, resolution) {
    if (resolution === "raw") {
        return {
            timestamp: row.measurement_time,
            power: row.power,
            consumption: row.consumption,
            gridPower: row.grid_power,
        };
    }
    if (resolution === "hourly") {
        return {
            timestamp: row.bucket,
            power: row.avgPower,
            consumption: null,
            gridPower: null,
        };
    }
    return {
        timestamp: `${row.bucket}T00:00:00.000Z`,
        power: row.avgPower,
        consumption: null,
        gridPower: null,
    };
}

function inferQuality(row, resolution) {
    if (resolution === "raw") {
        return typeof row.power === "number" ? "VALID" : (row.quality || "PARTIAL");
    }
    return row.hasInvalid ? "INVALID" : (row.partialCount > 0 ? "PARTIAL" : "VALID");
}

// Range-level capabilities derived from the bucket points.
function assessRangeCapabilities(points) {
    const hasGeneration = points.some((p) => p.generation !== null && p.generation !== undefined);
    const hasConsumption = points.some((p) => p.consumption !== null && p.consumption !== undefined);
    const hasGrid = points.some((p) => p.gridImport !== null || p.gridExport !== null);

    return {
        generation: hasGeneration ? "AVAILABLE" : "UNAVAILABLE",
        consumption: hasConsumption ? "AVAILABLE" : "UNAVAILABLE",
        grid: hasGrid ? "AVAILABLE" : "UNAVAILABLE",
        battery: "UNAVAILABLE",
        energyBalance: hasGeneration && hasConsumption ? "AVAILABLE" : "INSUFFICIENT_DATA",
    };
}

module.exports = {
    getCurrentFlow,
    getHistory,
    resolveOwnedScope,
    normalizeHistoryQuery,
    EnergyFlowValidationError,
    MAX_RANGE_MS,
    RESOLUTIONS,
};