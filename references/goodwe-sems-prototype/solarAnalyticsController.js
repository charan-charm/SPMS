// SolarAnalyticsController — HTTP concerns for GET /api/solar/analytics.
// Delegates to the service; maps errors to the existing SPMS conventions.
// Never leaks SQL errors, stack traces, or provider details.

const solarAnalyticsService = require("../services/solarAnalyticsService");
const { SolarError } = require("../utils/solarErrors");

function handleError(error, res) {
    if (error instanceof solarAnalyticsService.AnalyticsValidationError) {
        return res.status(error.statusCode).json(error.toJSON());
    }
    if (error instanceof SolarError) {
        console.error(`[solar-analytics] ${error.category}`);
        return res.status(error.httpStatus).json(error.toJSON());
    }
    console.error(`[solar-analytics] unexpected error (${error?.name || "Error"})`);
    return res.status(500).json({
        success: false,
        message: "Internal server error.",
        code: "INTERNAL_ERROR",
    });
}

function getAnalytics(req, res) {
    try {
        const result = solarAnalyticsService.getAnalytics(req.userId, req.query);
        return res.json({ analytics: result });
    } catch (error) {
        return handleError(error, res);
    }
}

module.exports = { getAnalytics };