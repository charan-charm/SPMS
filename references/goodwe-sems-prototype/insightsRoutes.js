const express = require("express");

const router = express.Router();

const insightsController = require("../controllers/insightsController");

// Intelligence endpoints — all require an authenticated SPMS session
// (ownership is enforced inside the service; single-owner model).
router.get("/insights", insightsController.getInsights);
router.get("/recommendations", insightsController.getRecommendations);

module.exports = router;
