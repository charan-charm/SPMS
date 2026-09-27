// InsightService (Phase 3.7) — intelligence orchestration.
//
// Transforms verified SPMS data into structured insights and recommendations
// using the deterministic layers, then optionally adds AI interpretation:
//
//   Route → Controller → InsightService
//     → solarAnalyticsService (verified analytics, bounded queries)
//     → electricityBillService (billing context only)
//     → insightFacts (structured facts) → insightEngine (insights)
//     → recommendationEngine (recommendations)
//     → aiService (only when AI_ENABLED=true; always falls back safely)
//
// No SQL here. No SEMS logic here. AI can never bypass the deterministic
// calculation layers.

const solarAnalyticsService = require("./solarAnalyticsService");
const electricityBillService = require("./electricityBillService");
const solarMeasurementRepository = require("../repositories/solarMeasurementRepository");
const config = require("../config/env");
const { createSolarError } = require("../utils/solarErrors");
const { buildInsightFacts, deriveConnection } = require("../utils/insightFacts");
const { generateInsights } = require("../utils/insightEngine");
const { generateRecommendations } = require("../utils/recommendationEngine");
const aiService = require("./aiService");

const VALID_RANGES = ["today", "7d", "30d", "custom"];
const MAX_RANGE_MS = 90 * 24 * 60 * 60 * 1000;

class InsightsValidationError extends Error {
    constructor(message, details = null) {
        super(message);
        this.name = "InsightsValidationError";
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

function startOfTodayUtc() {
    const d = new Date();
    d.setUTCHours(0, 0, 0, 0);
    return d;
}

// Validate the range contract. Returns { rangeKey, startTime, endTime, stationId, inverterSn }.
function normalizeRange(query = {}) {
    const errors = [];
    const rangeKey = query.range || "today";
    if (!VALID_RANGES.includes(rangeKey)) {
        errors.push(`range must be one of: ${VALID_RANGES.join(", ")}`);
    }

    let startTime, endTime;
    const end = new Date();
    if (rangeKey === "custom") {
        startTime = parseIso(query.startTime);
        endTime = parseIso(query.endTime);
        if (!query.startTime || !startTime) errors.push("startTime is required for custom range");
        if (!query.endTime || !endTime) errors.push("endTime is required for custom range");
    } else {
        const days = { today: 1, "7d": 7, "30d": 30 }[rangeKey] || 7;
        startTime = new Date(end.getTime() - days * 24 * 60 * 60 * 1000);
        if (rangeKey === "today") startTime = startOfTodayUtc();
        endTime = end;
    }

    if (startTime && endTime) {
        if (endTime.getTime() <= startTime.getTime()) {
            errors.push("endTime must be after startTime");
        }
        if (endTime.getTime() - startTime.getTime() > MAX_RANGE_MS) {
            errors.push("Requested range exceeds the 90-day maximum");
        }
    }

    if (errors.length > 0) {
        throw new InsightsValidationError("Invalid intelligence query.", errors);
    }

    return {
        rangeKey,
        startTime: startTime.toISOString(),
        endTime: endTime.toISOString(),
        stationId: typeof query.stationId === "string" && query.stationId.trim()
            ? query.stationId.trim() : null,
        inverterSn: typeof query.inverterSn === "string" && query.inverterSn.trim()
            ? query.inverterSn.trim() : null,
    };
}

// Compute the equal-length period immediately before the requested range.
function previousPeriod(range) {
    const start = new Date(range.startTime).getTime();
    const end = new Date(range.endTime).getTime();
    const durationMs = end - start;
    const prevEnd = new Date(start);
    const prevStart = new Date(start - durationMs);
    return {
        startTime: prevStart.toISOString(),
        endTime: prevEnd.toISOString(),
    };
}

function requireUser(userId) {
    if (!userId) {
        throw createSolarError("Authentication required.", "AUTHORIZATION_ERROR");
    }
}

// Build the ai block. Only attempts AI when includeAi is true AND the layer is
// enabled AND a key exists. Every failure falls back to deterministic output.
function buildAiBlock(includeAi, facts, insights, recommendations) {
    const enabled = config.ai.enabled && !!config.ai.apiKey;
    const base = {
        enabled: config.ai.enabled,
        available: false,
        summary: null,
        observations: [],
        recommendations: [],
        limitations: [],
    };
    if (!includeAi || !enabled) return base;

    return aiService
        .generateAiInterpretation({ facts, insights, recommendations })
        .then((result) => ({
            enabled: true,
            available: true,
            summary: result.summary,
            observations: result.observations,
            recommendations: result.recommendations,
            limitations: result.limitations,
        }))
        .catch(() => ({
            enabled: true,
            available: false,
            summary: null,
            observations: [],
            recommendations: [],
            limitations: ["AI interpretation is temporarily unavailable. Deterministic insights remain available."],
        }));
}

// Core pipeline: build facts, insights, recommendations for a user+range.
function buildIntelligence(userId, range) {
    requireUser(userId);

    const analytics = solarAnalyticsService.getAnalytics(userId, {
        range: "custom",
        startTime: range.startTime,
        endTime: range.endTime,
        resolution: "daily",
        stationId: range.stationId,
        inverterSn: range.inverterSn,
    });

    const prev = previousPeriod(range);
    const previousAnalytics = solarAnalyticsService.getAnalytics(userId, {
        range: "custom",
        startTime: prev.startTime,
        endTime: prev.endTime,
        resolution: "daily",
        stationId: range.stationId,
        inverterSn: range.inverterSn,
    });

    const bills = electricityBillService.listBills(userId);
    let billComparison = null;
    if (bills.length > 0) {
        try {
            billComparison = electricityBillService.getComparison(userId, bills[0].id);
        } catch (_) {
            billComparison = null;
        }
    }

    // Derive connection state from bounded raw measurements (daily/hourly
    // aggregates lose connection_status). This is a bounded query — never
    // unlimited history.
    const connRows = solarMeasurementRepository.findMeasurementsInRange({
        userId,
        stationId: analytics.stationId,
        inverterSn: analytics.inverterSn,
        startTime: range.startTime,
        endTime: range.endTime,
        limit: 500,
    });
    const connection = deriveConnection(connRows);
    analytics.connection = connection;

    const facts = buildInsightFacts({
        analytics,
        previousAnalytics,
        bills,
        billComparison,
        range,
    });

    const insights = generateInsights(facts);
    const recommendations = generateRecommendations(facts);

    return { facts, insights, recommendations };
}

function buildResponse({ facts, insights, recommendations, includeAi, includeInsights = true }) {
    const capabilities = {
        generation: facts.capabilities.generation,
        consumption: facts.capabilities.consumption,
        grid: facts.capabilities.grid,
        battery: facts.capabilities.battery,
        energyBalance: facts.capabilities.energyBalance,
        excess: facts.capabilities.excess,
        ai: config.ai.enabled ? "AVAILABLE" : "UNAVAILABLE",
    };

    const aiPromise = Promise.resolve(buildAiBlock(includeAi, facts, insights, recommendations));

    return aiPromise.then((ai) => ({
        capabilities,
        ...(includeInsights ? { insights } : {}),
        recommendations,
        ai,
    }));
}

// GET /api/insights — full intelligence response (deterministic + AI).
function getInsights(userId, query) {
    const range = normalizeRange(query);
    const { facts, insights, recommendations } = buildIntelligence(userId, range);
    return buildResponse({ facts, insights, recommendations, includeAi: true });
}

// GET /api/recommendations — deterministic recommendations (no AI).
function getRecommendations(userId, query) {
    const range = normalizeRange(query);
    const { facts, recommendations } = buildIntelligence(userId, range);
    return buildResponse({ facts, insights: [], recommendations, includeAi: false, includeInsights: false });
}

module.exports = {
    getInsights,
    getRecommendations,
    normalizeRange,
    buildIntelligence,
    buildAiBlock,
    InsightsValidationError,
    VALID_RANGES,
    MAX_RANGE_MS,
};
