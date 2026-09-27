const express = require("express");

const router = express.Router();

const {
    getStationStatistics,
} = require("../controllers/stationStatisticsController");

router.get("/", getStationStatistics);

module.exports = router;