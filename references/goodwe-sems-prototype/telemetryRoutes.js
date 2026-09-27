const express = require("express");

const router = express.Router();

const { getTelemetry } = require("../controllers/telemetryController");

router.get("/", getTelemetry);

module.exports = router;
