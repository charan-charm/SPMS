const express = require("express");

const router = express.Router();

const solarAnalyticsController = require("../controllers/solarAnalyticsController");

// Analytics require an authenticated SPMS session (ownership is enforced
// inside the service; single-owner model).
router.get("/", solarAnalyticsController.getAnalytics);

module.exports = router;