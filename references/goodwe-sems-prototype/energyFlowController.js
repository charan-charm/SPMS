// EnergyFlowController — HTTP concerns for the energy-flow module (Phase 3.4).
// Delegates to EnergyBalanceService; maps errors to the existing SPMS error
// conventions. Never leaks SQL errors, stack traces, or provider details.

const energyBalanceService = require("../services/energyBalanceService");
const { SolarError } = require("../utils/solarErrors");

function handleError(error, res) {
    // Validation errors (400) from the history query.
    if (error instanceof energyBalanceService.EnergyFlowValidationError) {
        return res.status(error.statusCode).json(error.toJSON());
    }
    // Normalized solar errors (401/403/500 etc.).
    if (error instanceof SolarError) {
        console.error(`[energy-flow] ${error.category}`);
        return res.status(error.httpStatus).json(error.toJSON());
    }
    // Unexpected errors — sanitized only.
    console.error(`[energy-flow] unexpected error (${error?.name || "Error"})`);
    return res.status(500).json({
        success: false,
        message: "Internal server error.",
        code: "INTERNAL_ERROR",
    });
}

async function getCurrent(req, res) {
    try {
        const result = await energyBalanceService.getCurrentFlow(req.userId, {
            tolerance: parseTolerance(req.query.tolerance),
        });
        return res.json(result);
    } catch (error) {
        return handleError(error, res);
    }
}

function getHistory(req, res) {
    try {
        const result = energyBalanceService.getHistory(req.userId, req.query);
        return res.json(result);
    } catch (error) {
        return handleError(error, res);
    }
}

// Parse an optional tolerance query param (kW). Invalid values fall back to
// the default — tolerance is a tuning knob, not an attack surface.
function parseTolerance(value) {
    if (value === undefined || value === null || value === "") return undefined;
    const num = Number(value);
    if (!Number.isFinite(num) || num < 0 || num > 100) return undefined;
    return num;
}

module.exports = { getCurrent, getHistory };