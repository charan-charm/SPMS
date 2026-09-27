// HistoricalSolarController — HTTP concerns for GET /api/solar/history.
// Delegates to the service; maps errors to the existing SPMS error conventions.
// Never leaks SQL errors, stack traces, or provider details.

const historicalSolarService = require("../services/historicalSolarService");
const { SolarError } = require("../utils/solarErrors");

function handleError(error, res) {
    // Validation errors (400) from the service.
    if (error instanceof historicalSolarService.HistoryValidationError) {
        return res.status(error.statusCode).json(error.toJSON());
    }
    // Normalized solar errors (403/404/500 etc.).
    if (error instanceof SolarError) {
        console.error(`[solar-history] ${error.category}`);
        return res.status(error.httpStatus).json(error.toJSON());
    }
    // Unexpected errors — sanitized only.
    console.error(`[solar-history] unexpected error (${error?.name || "Error"})`);
    return res.status(500).json({
        success: false,
        message: "Internal server error.",
        code: "INTERNAL_ERROR",
    });
}

function getHistory(req, res) {
    try {
        const result = historicalSolarService.getHistory(req.userId, req.query);
        return res.json(result);
    } catch (error) {
        return handleError(error, res);
    }
}

module.exports = { getHistory };
