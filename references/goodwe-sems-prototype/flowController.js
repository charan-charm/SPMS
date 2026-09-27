const { getFlow } = require("../services/goodweService");
const { respondWithInternalError } = require("../utils/safeError");

async function getFlowController(req, res) {
    try {
        const flow = await getFlow();

        res.json(flow);
    } catch (error) {
        respondWithInternalError(res, error, "flow");
    }
}

module.exports = {
    getFlow: getFlowController,
};