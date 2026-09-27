const express = require("express");

const router = express.Router();

const historicalSolarController = require("../controllers/historicalSolarController");

// Historical solar data — requires an authenticated SPMS session
// (ownership is enforced per-query inside the service).
router.get("/history", historicalSolarController.getHistory);

module.exports = router;
