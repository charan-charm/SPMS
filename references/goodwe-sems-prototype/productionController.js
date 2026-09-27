const { getProduction } = require("../services/goodweService");
const { respondWithInternalError } = require("../utils/safeError");

async function getProductionController(req, res) {
    try {
        res.json(await getProduction());
    } catch (error) {
        respondWithInternalError(res, error, "production");
    }
}

module.exports = {
    getProduction: getProductionController,
};