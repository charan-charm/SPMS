const { getCurve } = require("../services/goodweService");
const { respondWithInternalError } = require("../utils/safeError");

async function getCurveController(req, res) {
    try {
        const curve = await getCurve();

        res.json(curve);
    } catch (error) {
        respondWithInternalError(res, error, "curve");
    }
}

module.exports = {
    getCurve: getCurveController,
};