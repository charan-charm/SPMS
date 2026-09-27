const express = require("express");

const router = express.Router();

const { getPlantInfo } = require("../controllers/plantController");

router.get("/", getPlantInfo);

module.exports = router;