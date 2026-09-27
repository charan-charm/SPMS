const { getTelemetry } = require("../services/goodweService");
const { respondWithInternalError } = require("../utils/safeError");

async function getTelemetryController(req, res) {
    try {
        const telemetry = await getTelemetry();

        res.json(telemetry);
    } catch (error) {
        respondWithInternalError(res, error, "telemetry");
    }
}

module.exports = {
    getTelemetry: getTelemetryController,
};