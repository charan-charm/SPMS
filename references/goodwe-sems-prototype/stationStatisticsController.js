const { getStationStatistics } = require("../services/goodweService");
const { respondWithInternalError } = require("../utils/safeError");

async function getStationStatisticsController(req, res) {
    try {
        const statistics = await getStationStatistics();

        res.json(statistics);
    } catch (error) {
        respondWithInternalError(res, error, "station-statistics");
    }
}

module.exports = {
    getStationStatistics: getStationStatisticsController,
};