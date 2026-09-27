const { getPlantInfo } = require("../services/goodweService");
const { respondWithInternalError } = require("../utils/safeError");

async function getPlantInfoController(req, res) {
    try {
        const plant = await getPlantInfo();

        res.json(plant);
    } catch (error) {
        respondWithInternalError(res, error, "plant");
    }
}

module.exports = {
    getPlantInfo: getPlantInfoController,
};