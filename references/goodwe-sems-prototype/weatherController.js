const { getWeather } = require("../services/goodweService");
const { respondWithInternalError } = require("../utils/safeError");

async function getWeatherController(req, res) {
    try {
        res.json(await getWeather());
    } catch (error) {
        respondWithInternalError(res, error, "weather");
    }
}

module.exports = {
    getWeather: getWeatherController,
};