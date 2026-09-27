const express = require("express");

const router = express.Router();

const energyFlowController = require("../controllers/energyFlowController");

// Energy-flow endpoints — all require an authenticated SPMS session
// (ownership is enforced inside the service; single-owner model).
router.get("/current", energyFlowController.getCurrent);
router.get("/history", energyFlowController.getHistory);

module.exports = router;