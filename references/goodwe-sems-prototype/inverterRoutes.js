const express = require("express");

const router = express.Router();

const { getInverters } = require("../controllers/inverterController");

router.get("/", getInverters);

module.exports = router;