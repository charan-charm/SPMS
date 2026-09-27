const express = require("express");

const router = express.Router();

const solarController = require("../controllers/solarController");

// All solar endpoints require an authenticated SPMS session.
router.get("/stations", solarController.getStations);
router.get("/inverters", solarController.getInverters);
router.get("/current", solarController.getCurrent);
router.get("/energy-summary", solarController.getEnergySummary);
router.get("/connection", solarController.getConnection);
router.post("/refresh", solarController.refresh);
router.get("/collector-health", solarController.getCollectorHealth);

module.exports = router;
