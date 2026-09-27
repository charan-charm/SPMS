const { getInverters } = require("../services/goodweService");
const { respondWithInternalError } = require("../utils/safeError");

async function getInvertersController(req, res) {
    try {
        const data = await getInverters();

        res.json(data);
    } catch (error) {
        respondWithInternalError(res, error, "inverters");
    }
}

module.exports = {
    getInverters: getInvertersController,
};