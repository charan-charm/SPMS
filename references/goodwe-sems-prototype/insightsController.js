// InsightsController — HTTP concerns for the intelligence module (Phase 3.7).
// Delegates to InsightService; maps errors to the existing SPMS conventions.
// Never leaks SQL errors, stack traces, or provider details.

const insightService = require("../services/insightService");
const { SolarError } = require("../utils/solarErrors");

function handleError(error, res) {
    if (error instanceof insightService.InsightsValidationError) {
        return res.status(error.statusCode).json(error.toJSON());
    }
    if (error instanceof SolarError) {
        console.error(`[insights] ${error.category}`);
        return res.status(error.httpStatus).json(error.toJSON());
    }
    console.error(`[insights] unexpected error (${error?.name || "Error"})`);
    return res.status(500).json({
        success: false,
        message: "Internal server error.",
        code: "INTERNAL_ERROR",
    });
}

async function getInsights(req, res) {
    try {
        const result = await insightService.getInsights(req.userId, req.query);
        return res.json(result);
    } catch (error) {
        return handleError(error, res);
    }
}

async function getRecommendations(req, res) {
    try {
        const result = await insightService.getRecommendations(req.userId, req.query);
        return res.json(result);
    } catch (error) {
        return handleError(error, res);
    }
}

module.exports = { getInsights, getRecommendations };
