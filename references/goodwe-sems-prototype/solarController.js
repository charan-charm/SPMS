const solarService = require("../services/solarService");
const solarCollector = require("../services/solarCollector");
const { SolarError } = require("../utils/solarErrors");

function handleError(error, res) {
    if (error instanceof SolarError) {
        // Safe logging: category only — never the raw provider message,
        // which could contain credentials/tokens.
        console.error(`[solar] ${error.category}`);
        return res.status(error.httpStatus).json(error.toJSON());
    }
    // Unexpected error — log a sanitized hint, never the raw error object.
    console.error(`[solar] unexpected error (${error?.name || "Error"})`);
    return res.status(500).json({
        success: false,
        message: "Internal server error.",
        code: "INTERNAL_ERROR",
    });
}

async function getStations(req, res) {
    try {
        const stations = await solarService.getStations();
        return res.json({ stations });
    } catch (error) {
        return handleError(error, res);
    }
}

async function getInverters(req, res) {
    try {
        const inverters = await solarService.getInverters();
        return res.json({ inverters });
    } catch (error) {
        return handleError(error, res);
    }
}

async function getCurrent(req, res) {
    try {
        const snapshot = await solarService.getCurrentSnapshot();
        return res.json({ snapshot });
    } catch (error) {
        return handleError(error, res);
    }
}

async function getEnergySummary(req, res) {
    try {
        const { startDate, endDate } = req.query;
        const summary = await solarService.getEnergySummary(startDate, endDate);
        return res.json({ summary });
    } catch (error) {
        return handleError(error, res);
    }
}

async function getConnection(req, res) {
    try {
        const status = await solarService.getConnectionStatus();
        return res.json({ connection: status });
    } catch (error) {
        return handleError(error, res);
    }
}

async function refresh(req, res) {
    try {
        const result = await solarService.refresh();
        return res.json(result);
    } catch (error) {
        return handleError(error, res);
    }
}

async function getCollectorHealth(req, res) {
    try {
        const health = solarCollector.getCollectorHealth();
        return res.json({ health });
    } catch (error) {
        return handleError(error, res);
    }
}

module.exports = {
    getStations,
    getInverters,
    getCurrent,
    getEnergySummary,
    getConnection,
    refresh,
    getCollectorHealth,
};